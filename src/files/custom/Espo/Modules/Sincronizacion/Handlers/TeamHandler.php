<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;

class TeamHandler
{
    private EntityManager $entityManager;
    private array $incidencias = [];
    
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
                $this->addLog('created', 'Team', $claId, $nombre, 'success', "CLA creado: {$nombre}", $configId);
            } else {
                $existentes++;
            }
        }
        $this->addLog('updated', 'Team', null, 'CLAs', 'success', "CLAs: {$existentes} existentes, {$creados} creados", $configId);
    }
    
    public function syncAfiliados(array $afiliadosExternos, string $configId, array &$summary): void
    {
        // Obtener equipos existentes (solo activos, sin deleted)
        $existingTeams = [];
        $teams = $this->entityManager->getRDBRepository('Team')
            ->where(['deleted' => false])
            ->find();
        
        $oficinaCount = 0;
        foreach ($teams as $team) {
            $teamId = $team->getId();
            // Las oficinas tienen ID numérico, los CLAs tienen formato "CLAx"
            if (is_numeric($teamId)) {
                $existingTeams[$teamId] = $team->getId();
                $oficinaCount++;
            }
        }
        
        error_log('[TeamHandler] Oficinas (teams con ID numérico) encontradas: ' . $oficinaCount);
        
        $processedTeams = [];
        $contador = 0;
        $totalAfiliados = count($afiliadosExternos);
        
        foreach ($afiliadosExternos as $afiliado) {
            try {
                $contador++;
                $licencia = $afiliado['licencia'];
                $nombre = trim($afiliado['nombre']);
                $zona = $afiliado['zona'];
                
                if (empty($licencia) || empty($nombre)) {
                    error_log("[TeamHandler] Afiliado id={$licencia} omitido: licencia o nombre vacío");
                    continue;
                }
                
                // Las oficinas usan la licencia como ID directo
                $teamId = $licencia;
                $processedTeams[$teamId] = true;
                
                // Validar que la zona exista en CLAs
                if (!isset(self::CLAS[$zona])) {
                    error_log("[TeamHandler] ERROR: Zona inválida {$zona} para oficina {$nombre}");
                    $this->addIncidencia('missing_zona', 'Team', null, $nombre,
                                       "Oficina '{$nombre}' tiene zona inválida: {$zona}");
                    continue;
                }
                
                // Buscar CLA padre
                $claId = "CLA{$zona}";
                
                $claPadre = $this->entityManager->getEntityById('Team', $claId);
                
                if (!$claPadre) {
                    error_log("[TeamHandler] ERROR: CLA padre {$claId} no encontrado para oficina {$nombre}");
                    $this->addIncidencia('missing_team', 'Team', null, $nombre,
                                       "CLA padre no encontrado para oficina '{$nombre}' (Zona: {$zona})");
                    continue;
                }
                                
                // Buscar o crear oficina (usando ID directo, no externalId)
                $team = $this->entityManager->getEntityById('Team', $teamId);
                
                if (!$team) {
                    error_log("[TeamHandler] Oficina {$teamId} NO existe, creando nueva...");
                    
                    // Crear nueva oficina
                    $team = $this->entityManager->getNewEntity('Team');
                    $team->set('id', $teamId);
                    $team->set('name', $nombre);
                    $this->entityManager->saveEntity($team);
                    
                    $summary['teams']['created']++;
                    $this->addLog('created', 'Team', $team->getId(), $nombre, 'success',
                                 "Oficina '{$nombre}' creada (Licencia: {$licencia}, CLA: {$zona})", $configId);
                    error_log("[TeamHandler] ✓ Oficina creada: {$nombre} (ID: {$team->getId()})");
                    
                } else {                    
                    // Verificar si necesita actualización
                    $needsUpdate = false;
                    $changes = [];
                    
                    if ($team->get('name') !== $nombre) {
                        $team->set('name', $nombre);
                        $needsUpdate = true;
                        $changes[] = "nombre: '{$team->get('name')}' -> '{$nombre}'";
                    }
                    
                    if ($needsUpdate) {
                        $this->entityManager->saveEntity($team);
                        $summary['teams']['updated']++;
                        $changesStr = implode(', ', $changes);
                        $this->addLog('updated', 'Team', $team->getId(), $nombre, 'success',
                                     "Oficina actualizada: {$changesStr}", $configId);
                        error_log("[TeamHandler] ✓ Oficina actualizada: {$nombre} - {$changesStr}");
                    } else {
                        error_log("[TeamHandler] - Sin cambios necesarios para {$nombre}");
                    }
                }
                
            } catch (\Exception $e) {
                $summary['teams']['errors']++;
                $nombre = $afiliado['nombre'] ?? 'Desconocido';
                $mensaje = "Error sincronizando oficina '{$nombre}': " . $e->getMessage();
                error_log("[TeamHandler] ERROR: {$mensaje}");
                error_log("[TeamHandler] Stack trace: " . $e->getTraceAsString());
                $this->addIncidencia('sync_error', 'Team', null, $nombre, $mensaje);
                $this->addLog('error', 'Team', null, $nombre, 'error', $mensaje, $configId);
            }
        }
        
        error_log('[TeamHandler] Finalizando sincronización de oficinas. Equipos procesados: ' . count($processedTeams));

        // Eliminar oficinas que ya no existen en BD externa
        error_log('[TeamHandler] Verificando oficinas a eliminar...');

        // IDs externos activos (licencias)
        $licenciasActivas = array_map('strval', array_column($afiliadosExternos, 'licencia'));

        // Obtener TODOS los equipos numéricos (oficinas) que NO estén eliminados
        $equiposActuales = $this->entityManager->getRDBRepository('Team')
            ->where([
                'deleted' => false
            ])
            ->find();

        $eliminados = 0;
        foreach ($equiposActuales as $equipo) {
            $equipoId = $equipo->getId();
            
            // Si NO es numérico, saltar (CLAs, etc)
            if (!is_numeric($equipoId)) {
                continue;
            }
            
            // Si NO está en licencias activas, ELIMINAR
            if (!in_array($equipoId, $licenciasActivas)) {
                try {
                    error_log("[TeamHandler] Oficina {$equipoId} ({$equipo->get('name')}) no está en BD externa, eliminando...");
                    
                    // Desactivar usuarios del equipo primero
                    $usuariosEquipo = $this->entityManager->getRDBRepository('User')
                        ->where(['defaultTeamId' => $equipoId, 'isActive' => true])
                        ->find();
                    
                    $usuariosDesactivados = 0;
                    foreach ($usuariosEquipo as $usuario) {
                        $usuario->set('isActive', false);
                        $this->entityManager->saveEntity($usuario);
                        $usuariosDesactivados++;
                    }
                    
                    if ($usuariosDesactivados > 0) {
                        error_log("[TeamHandler] {$usuariosDesactivados} usuarios desactivados del equipo {$equipoId}");
                    }
                    
                    // Eliminar equipo (borrado lógico)
                    $this->entityManager->removeEntity($equipo);
                    
                    $eliminados++;
                    $summary['teams']['deleted']++;
                    $this->addLog('deleted', 'Team', $equipoId, $equipo->get('name'), 'success',
                                 "Oficina eliminada (no existe en BD externa)", $configId);
                    error_log("[TeamHandler] ✓ Equipo {$equipoId} ELIMINADO (inactivo en BD externa)");
                    
                } catch (\Exception $e) {
                    error_log("[TeamHandler] ERROR eliminando equipo {$equipoId}: " . $e->getMessage());
                }
            }
        }

        error_log("[TeamHandler] Total equipos eliminados: {$eliminados}");
    }
    
    /**
     * Verificar si un equipo existe
     */
    public function teamExists(string $teamId): bool
    {
        try {
            $team = $this->entityManager->getEntityById('Team', $teamId);
            return $team !== null;
        } catch (\Exception $e) {
            error_log("[TeamHandler] Error verificando existencia de equipo {$teamId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener incidencias acumuladas
     */
    public function getIncidencias(): array
    {
        return $this->incidencias;
    }
    
    /**
     * Agregar incidencia
     */
    private function addIncidencia(string $tipo, string $entityType, ?string $entityId, string $entityName, string $mensaje): void
    {
        $this->incidencias[] = [
            'tipo' => $tipo,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'entityName' => $entityName,
            'mensaje' => $mensaje
        ];
    }

    /**
     * Agregar log de sincronización
     */
    private function addLog(string $action, string $entityType, ?string $entityId, string $entityName, 
                           string $status, string $message, ?string $configId = null): void
    {
        try {
            $log = $this->entityManager->getNewEntity('SyncLog');
            $log->set([
                'name' => "{$entityType}: {$entityName}",
                'syncDate' => date('Y-m-d H:i:s'),
                'entityType' => $entityType,
                'entityId' => $entityId,
                'entityName' => $entityName,
                'action' => $action,
                'status' => $status,
                'message' => $message
            ]);
            
            if ($configId) {
                $log->set('configId', $configId);
            }
            
            $this->entityManager->saveEntity($log);
        } catch (\Exception $e) {
            error_log('[TeamHandler] Error creando log: ' . $e->getMessage());
        }
    }
}
