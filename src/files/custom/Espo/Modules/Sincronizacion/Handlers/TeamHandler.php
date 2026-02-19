<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Traits\Loggable;

class TeamHandler
{
    use Loggable;

    private EntityManager $entityManager;
    
    private const CLAS = [
        0 => 'Territorio Nacional',
        1 => 'Caracas Libertador',
        2 => 'Caracas Noreste',
        3 => 'Caracas Sureste',
        4 => 'Centro Occidente',
        5 => 'Llano Andes',
        6 => 'Oriente Insular',
        7 => 'Oriente Norte',
        8 => 'Oriente Sur',
        9 => 'Zulia'
    ];
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function syncCLAs(string $configId, array &$summary): void
    {
        $claNombres = [
            'CLA0' => 'Territorio Nacional',
            'CLA1' => 'Caracas Libertador',
            'CLA2' => 'Caracas Noreste',
            'CLA3' => 'Caracas Sureste',
            'CLA4' => 'Centro Occidente',
            'CLA5' => 'Llano Andes',
            'CLA6' => 'Oriente Insular',
            'CLA7' => 'Oriente Norte',
            'CLA8' => 'Oriente Sur',
            'CLA9' => 'Zulia'
        ];
        
        $creados = 0;
        $existentes = 0;
        
        foreach ($claNombres as $claId => $nombre) {
            $cla = $this->entityManager->getEntity('Team', $claId);
            
            if (!$cla) {
                $cla = $this->entityManager->getNewEntity('Team');
                $cla->set('id', $claId);
                $cla->set('name', $nombre);
                $this->entityManager->saveEntity($cla);
                $creados++;
                $this->log('created', 'Team', $claId, $nombre, 'success', "CLA creado", $configId);
            } else {
                $existentes++;
            }
        }
        
        $this->log('info', 'Team', null, 'Resumen CLAs', 'success',
                  "CLAs: {$existentes} existentes, {$creados} creados", $configId);
    }
    
    public function syncAfiliados(array $afiliadosExternos, string $configId, array &$summary): void
    {
        $existingTeams = [];
        $teams = $this->entityManager->getRDBRepository('Team')
            ->where(['deleted' => false])
            ->find();
        
        foreach ($teams as $team) {
            $teamId = $team->getId();
            if (is_numeric($teamId)) {
                $existingTeams[$teamId] = $team->getId();
            }
        }
        
        $processedTeams = [];
        
        foreach ($afiliadosExternos as $afiliado) {
            try {
                $licencia = $afiliado['licencia'];
                $nombre = trim($afiliado['nombre']);
                $zona = $afiliado['zona'];
                
                if (empty($licencia) || empty($nombre)) {
                    $this->log('info', 'Team', null, 'Afiliado', 'warning',
                              "Afiliado omitido: licencia o nombre vacío", $configId);
                    continue;
                }
                
                $teamId = $licencia;
                $processedTeams[$teamId] = true;
                
                if (!isset(self::CLAS[$zona])) {
                    $this->log('info', 'Team', $teamId, $nombre, 'warning',
                              "Oficina tiene zona inválida: {$zona}", $configId);
                    $summary['teams']['skipped'] = ($summary['teams']['skipped'] ?? 0) + 1;
                    continue;
                }
                
                $claId = "CLA{$zona}";
                $claPadre = $this->entityManager->getEntityById('Team', $claId);
                
                if (!$claPadre) {
                    $this->log('error', 'Team', $teamId, $nombre, 'error',
                              "CLA padre no encontrado para oficina (Zona: {$zona})", $configId);
                    $summary['teams']['errors']++;
                    continue;
                }
                
                $team = $this->entityManager->getEntityById('Team', $teamId);
                
                if (!$team) {
                    $team = $this->entityManager->getNewEntity('Team');
                    $team->set('id', $teamId);
                    $team->set('name', $nombre);
                    $this->entityManager->saveEntity($team);
                    
                    $summary['teams']['created'] = ($summary['teams']['created'] ?? 0) + 1;
                    $this->log('created', 'Team', $team->getId(), $nombre, 'success',
                              "Oficina creada", $configId);
                    
                } else {
                    $needsUpdate = false;
                    $changes = [];
                    
                    if ($team->get('name') !== $nombre) {
                        $team->set('name', $nombre);
                        $needsUpdate = true;
                        $changes[] = "nombre";
                    }
                    
                    if ($needsUpdate) {
                        $this->entityManager->saveEntity($team);
                        $summary['teams']['updated'] = ($summary['teams']['updated'] ?? 0) + 1;
                        $changesStr = implode(', ', $changes);
                        $this->log('updated', 'Team', $team->getId(), $nombre, 'success',
                                  "Oficina actualizada: {$changesStr}", $configId);
                    } else {
                        $summary['teams']['no_changes'] = ($summary['teams']['no_changes'] ?? 0) + 1;
                    }
                }
                
            } catch (\Exception $e) {
                $summary['teams']['errors'] = ($summary['teams']['errors'] ?? 0) + 1;
                $nombre = $afiliado['nombre'] ?? 'Desconocido';
                $licencia = $afiliado['licencia'] ?? 'Unknown';
                $this->log('error', 'Team', $licencia, $nombre, 'error',
                          "Error sincronizando oficina: " . $e->getMessage(), $configId);
            }
        }

        $licenciasActivas = array_map('strval', array_column($afiliadosExternos, 'licencia'));
        $equiposActuales = $this->entityManager->getRDBRepository('Team')
            ->where(['deleted' => false])
            ->find();

        $eliminados = 0;
        foreach ($equiposActuales as $equipo) {
            $equipoId = $equipo->getId();
            
            if (!is_numeric($equipoId)) {
                continue;
            }
            
            if (!in_array($equipoId, $licenciasActivas)) {
                try {
                    $usuariosEquipo = $this->entityManager->getRDBRepository('User')
                        ->where(['defaultTeamId' => $equipoId, 'isActive' => true])
                        ->find();
                    
                    foreach ($usuariosEquipo as $usuario) {
                        $usuario->set('isActive', false);
                        $this->entityManager->saveEntity($usuario);
                    }
                    
                    $this->entityManager->removeEntity($equipo);
                    
                    $eliminados++;
                    $summary['teams']['deleted'] = ($summary['teams']['deleted'] ?? 0) + 1;
                    $this->log('deleted', 'Team', $equipoId, $equipo->get('name'), 'success',
                              "Oficina eliminada (no existe en BD externa)", $configId);
                    
                } catch (\Exception $e) {
                    $summary['teams']['errors'] = ($summary['teams']['errors'] ?? 0) + 1;
                    $this->log('error', 'Team', $equipoId, $equipo->get('name'), 'error',
                              "Error eliminando equipo: " . $e->getMessage(), $configId);
                }
            }
        }

        $this->log('info', 'Team', null, 'Resumen Oficinas', 'success',
                  "Creadas: " . ($summary['teams']['created'] ?? 0) . " | " .
                  "Actualizadas: " . ($summary['teams']['updated'] ?? 0) . " | " .
                  "Sin cambios: " . ($summary['teams']['no_changes'] ?? 0) . " | " .
                  "Eliminadas: " . ($summary['teams']['deleted'] ?? 0) . " | " .
                  "Omitidas: " . ($summary['teams']['skipped'] ?? 0) . " | " .
                  "Errores: " . ($summary['teams']['errors'] ?? 0), $configId);
    }
    
    public function teamExists(string $teamId): bool
    {
        try {
            $team = $this->entityManager->getEntityById('Team', $teamId);
            return $team !== null;
        } catch (\Exception $e) {
            return false;
        }
    }
}