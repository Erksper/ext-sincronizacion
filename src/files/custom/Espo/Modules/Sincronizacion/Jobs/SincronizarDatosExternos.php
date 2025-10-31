<?php

namespace Espo\Modules\Sincronizacion\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;

/**
 * Job programado que sincroniza usuarios y teams desde una base de datos externa
 */
class SincronizarDatosExternos implements JobDataLess
{
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    private array $incidencias = [];
    
    // CLAs fijos del sistema
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
    
    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(): void
    {
        try {
            // SOLUCIÓN TIMEOUT: Aumentar límites
            set_time_limit(0);
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 0);
            
            error_log('[SyncJob] ========== INICIANDO SINCRONIZACIÓN ==========');
            
            // 1. Obtener configuración activa
            $config = $this->getActiveConfig();
            if (!$config) {
                error_log('[SyncJob] No hay configuración activa de BD externa');
                return;
            }
            
            error_log("[SyncJob] Usando configuración: {$config['name']}");
            
            // 2. Conectar a la base de datos externa
            $pdo = $this->connectToExternalDb($config);
            if (!$pdo) {
                $this->updateConfigStatus($config['id'], 'error');
                error_log('[SyncJob] No se pudo conectar a la BD externa');
                return;
            }
            
            // 3. CONSULTAS INICIALES - Todas al principio
            error_log('[SyncJob] Consultando datos de BD externa...');
            
            // Consulta de usuarios
            $sqlUsuarios = "SELECT id, idAfiliados, nombre, apellidoP, username, password, email, telMovil, puesto 
                           FROM usuarios 
                           WHERE isActive = 1 AND idAfiliados IS NOT NULL";
            $stmtUsuarios = $pdo->prepare($sqlUsuarios);
            $stmtUsuarios->execute();
            $usuariosExternos = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
            
            // Consulta de afiliados
            $sqlAfiliados = "SELECT licencia, nombre, zona 
                            FROM afiliados 
                            WHERE isActive = 1
                            and (suspendida = 0 or suspendida IS NULL)";
            $stmtAfiliados = $pdo->prepare($sqlAfiliados);
            $stmtAfiliados->execute();
            $afiliadosExternos = $stmtAfiliados->fetchAll(PDO::FETCH_ASSOC);
            
            // Consulta de roles distintos
            $sqlRoles = "SELECT DISTINCT puesto 
                        FROM usuarios 
                        WHERE puesto IS NOT NULL 
                        ORDER BY puesto";
            $stmtRoles = $pdo->prepare($sqlRoles);
            $stmtRoles->execute();
            $rolesExternos = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);
            
            $pdo = null; // Cerrar conexión
            
            error_log('[SyncJob] Datos obtenidos: ' . count($usuariosExternos) . ' usuarios, ' . 
                     count($afiliadosExternos) . ' afiliados, ' . count($rolesExternos) . ' roles');
            
            // 4. Ejecutar sincronización en orden
            $summary = [
                'roles' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'clas' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'teams' => ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0],
                'users' => ['created' => 0, 'updated' => 0, 'disabled' => 0, 'errors' => 0, 'skipped' => 0, 'no_changes' => 0]
            ];
            
            $this->syncRoles($rolesExternos, $config['id'], $summary);
            $this->syncCLAs($config['id'], $summary);
            $this->syncAfiliados($afiliadosExternos, $config['id'], $summary);
            $this->syncUsuarios($usuariosExternos, $afiliadosExternos, $rolesExternos, $config['id'], $summary);
            
            // 5. Limpiar logs antiguos
            $this->cleanOldLogs();
            
            // 6. Determinar estado final
            $status = 'success';
            $hasErrors = $summary['roles']['errors'] > 0 || 
                        $summary['clas']['errors'] > 0 || 
                        $summary['teams']['errors'] > 0 || 
                        $summary['users']['errors'] > 0;
            
            if ($hasErrors || count($this->incidencias) > 0) {
                $status = 'warning';
            }
            
            $this->updateConfigStatus($config['id'], $status);
            
            // 7. Enviar email con incidencias si las hay
            if (count($this->incidencias) > 0 && !empty($config['notificationEmail'])) {
                $this->sendIncidenciasEmail($config['notificationEmail'], $summary);
            }
            
            // 8. Log resumen
            error_log('[SyncJob] ========== SINCRONIZACIÓN COMPLETADA ==========');
            error_log('[SyncJob] Roles - Creados: ' . $summary['roles']['created'] . ' | Existentes: ' . $summary['roles']['existing'] . ' | Errores: ' . $summary['roles']['errors']);
            error_log('[SyncJob] CLAs - Creados: ' . $summary['clas']['created'] . ' | Existentes: ' . $summary['clas']['existing']);
            error_log('[SyncJob] Teams - Creados: ' . $summary['teams']['created'] . ' | Actualizados: ' . $summary['teams']['updated'] . ' | Eliminados: ' . $summary['teams']['deleted'] . ' | Errores: ' . $summary['teams']['errors']);
            error_log('[SyncJob] Users - Creados: ' . $summary['users']['created'] . ' | Actualizados: ' . $summary['users']['updated'] . ' | Desactivados: ' . $summary['users']['disabled'] . ' | Sin cambios: ' . $summary['users']['no_changes'] . ' | Errores: ' . $summary['users']['errors']);
            error_log('[SyncJob] Total incidencias notificadas: ' . count($this->incidencias));
            
        } catch (\Exception $e) {
            error_log('[SyncJob] Error crítico: ' . $e->getMessage());
            error_log('[SyncJob] Trace: ' . $e->getTraceAsString());
        }
    }
    
    /**
     * PASO 1: Sincronizar Roles
     */
    private function syncRoles(array $rolesExternos, string $configId, array &$summary): void
    {
        error_log('[SyncJob] === PASO 1: Sincronizando Roles ===');
        
        foreach ($rolesExternos as $puestoOriginal) {
            try {
                // Mantener el nombre original del rol
                $nombreRol = $puestoOriginal;
                
                // Verificar si el rol existe
                $rol = $this->entityManager->getRDBRepository('Role')
                    ->where(['name' => $nombreRol])
                    ->findOne();
                
                if (!$rol) {
                    // Crear rol nuevo
                    $rol = $this->entityManager->getNewEntity('Role');
                    $rol->set('name', $nombreRol);
                    $this->entityManager->saveEntity($rol);
                    
                    $summary['roles']['created']++;
                    $this->addLog('created', 'Role', $rol->getId(), $nombreRol, 'success', 
                                 "Rol '{$nombreRol}' creado automáticamente", $configId);
                    error_log("[SyncJob] Rol creado: {$nombreRol}");
                } else {
                    $summary['roles']['existing']++;
                    error_log("[SyncJob] Rol existente: {$nombreRol}");
                }
                
            } catch (\Exception $e) {
                $summary['roles']['errors']++;
                $mensaje = "Error creando rol '{$puestoOriginal}': " . $e->getMessage();
                $this->addIncidencia('validation_error', 'Role', null, $puestoOriginal, $mensaje);
                $this->addLog('error', 'Role', null, $puestoOriginal, 'error', $mensaje, $configId);
                error_log("[SyncJob] {$mensaje}");
            }
        }
    }
    
    /**
     * PASO 2: Sincronizar CLAs
     */
    private function syncCLAs(string $configId, array &$summary): void
    {
        error_log('[SyncJob] === PASO 2: Sincronizando CLAs ===');
        
        foreach (self::CLAS as $numero => $nombre) {
            try {
                $claId = 'CLA' . $numero;
                
                $cla = $this->entityManager->getEntityById('Team', $claId);
                
                if (!$cla) {
                    // Crear CLA
                    $cla = $this->entityManager->getNewEntity('Team');
                    $cla->set('id', $claId);
                    $cla->set('name', $nombre);
                    $this->entityManager->saveEntity($cla);
                    
                    $summary['clas']['created']++;
                    $this->addLog('created', 'Team', $claId, $nombre, 'success', 
                                 "CLA '{$nombre}' creado", $configId);
                    error_log("[SyncJob] CLA creado: {$claId} - {$nombre}");
                } else {
                    // Actualizar nombre si cambió
                    if ($cla->get('name') !== $nombre) {
                        $cla->set('name', $nombre);
                        $this->entityManager->saveEntity($cla);
                        error_log("[SyncJob] CLA actualizado: {$claId} - {$nombre}");
                    }
                    
                    $summary['clas']['existing']++;
                    error_log("[SyncJob] CLA existente: {$claId} - {$nombre}");
                }
                
            } catch (\Exception $e) {
                $summary['clas']['errors']++;
                $mensaje = "Error creando CLA{$numero}: " . $e->getMessage();
                $this->addIncidencia('validation_error', 'Team', 'CLA' . $numero, $nombre, $mensaje);
                $this->addLog('error', 'Team', 'CLA' . $numero, $nombre, 'error', $mensaje, $configId);
                error_log("[SyncJob] {$mensaje}");
            }
        }
    }
    
    /**
     * PASO 3: Sincronizar Afiliados (Equipos/Oficinas)
     */
    private function syncAfiliados(array $afiliadosExternos, string $configId, array &$summary): void
    {
        error_log('[SyncJob] === PASO 3: Sincronizando Afiliados (Teams) ===');
        
        $idsExternos = [];
        
        foreach ($afiliadosExternos as $afiliado) {
            try {
                // VALIDACIÓN: campos obligatorios
                if (empty($afiliado['licencia'])) {
                    $summary['teams']['errors']++;
                    $mensaje = "Afiliado con licencia NULL o vacía";
                    $this->addIncidencia('validation_error', 'Team', null, $afiliado['nombre'] ?? 'Desconocido', $mensaje);
                    $this->addLog('error', 'Team', null, $afiliado['nombre'] ?? 'Desconocido', 'error', $mensaje, $configId);
                    continue;
                }
                
                if (!isset($afiliado['zona']) || $afiliado['zona'] === null || $afiliado['zona'] === '') {
                    $summary['teams']['errors']++;
                    $mensaje = "Afiliado '{$afiliado['licencia']}': campo zona es NULL o vacío";
                    $this->addIncidencia('validation_error', 'Team', $afiliado['licencia'], $afiliado['nombre'], $mensaje);
                    $this->addLog('error', 'Team', $afiliado['licencia'], $afiliado['nombre'], 'error', $mensaje, $configId);
                    continue;
                }
                
                if (empty($afiliado['nombre'])) {
                    $summary['teams']['errors']++;
                    $mensaje = "Afiliado '{$afiliado['licencia']}': campo nombre es NULL o vacío";
                    $this->addIncidencia('validation_error', 'Team', $afiliado['licencia'], 'Sin nombre', $mensaje);
                    $this->addLog('error', 'Team', $afiliado['licencia'], 'Sin nombre', 'error', $mensaje, $configId);
                    continue;
                }
                
                // VALIDACIÓN: zona válida (0-9)
                $zona = (int)$afiliado['zona'];
                if ($zona < 0 || $zona > 9) {
                    $summary['teams']['errors']++;
                    $mensaje = "Afiliado '{$afiliado['licencia']}': zona '{$afiliado['zona']}' fuera de rango (debe ser 0-9)";
                    $this->addIncidencia('validation_error', 'Team', $afiliado['licencia'], $afiliado['nombre'], $mensaje);
                    $this->addLog('error', 'Team', $afiliado['licencia'], $afiliado['nombre'], 'error', $mensaje, $configId);
                    continue;
                }
                
                $teamId = $afiliado['licencia'];
                $idsExternos[] = $teamId;
                
                $team = $this->entityManager->getEntityById('Team', $teamId);
                
                if (!$team) {
                    // Crear team
                    $team = $this->entityManager->getNewEntity('Team');
                    $team->set('id', $teamId);
                    $team->set('name', $afiliado['nombre']);
                    $this->entityManager->saveEntity($team);
                    
                    $summary['teams']['created']++;
                    $this->addLog('created', 'Team', $teamId, $afiliado['nombre'], 'success', 
                                 'Team creado', $configId);
                    error_log("[SyncJob] Team creado: {$teamId} - {$afiliado['nombre']}");
                } else {
                    // Actualizar si cambió el nombre
                    if ($team->get('name') !== $afiliado['nombre']) {
                        $team->set('name', $afiliado['nombre']);
                        $this->entityManager->saveEntity($team);
                        
                        $summary['teams']['updated']++;
                        $this->addLog('updated', 'Team', $teamId, $afiliado['nombre'], 'success', 
                                     'Team actualizado', $configId);
                        error_log("[SyncJob] Team actualizado: {$teamId} - {$afiliado['nombre']}");
                    }
                }
                
            } catch (\Exception $e) {
                $summary['teams']['errors']++;
                $mensaje = "Error procesando afiliado: " . $e->getMessage();
                $this->addIncidencia('sync_error', 'Team', $afiliado['licencia'] ?? null, $afiliado['nombre'] ?? 'Desconocido', $mensaje);
                $this->addLog('error', 'Team', $afiliado['licencia'] ?? null, $afiliado['nombre'] ?? 'Desconocido', 'error', $mensaje, $configId);
                error_log("[SyncJob] {$mensaje}");
            }
        }
        
        // Eliminar teams que ya no existen (excepto CLAs)
        $this->deleteRemovedTeams($idsExternos, $configId, $summary);
    }
    
    /**
     * Eliminar teams que ya no existen en BD externa (excepto CLAs)
     */
    private function deleteRemovedTeams(array $idsExternos, string $configId, array &$summary): void
    {
        try {
            $allTeams = $this->entityManager->getRDBRepository('Team')->find();
            
            foreach ($allTeams as $team) {
                $teamId = $team->getId();
                
                // No eliminar CLAs
                if (strpos($teamId, 'CLA') === 0) {
                    continue;
                }
                
                // Si el team no está en la lista externa, eliminarlo
                if (!in_array($teamId, $idsExternos)) {
                    $teamName = $team->get('name');
                    $this->entityManager->removeEntity($team);
                    
                    $summary['teams']['deleted']++;
                    $this->addLog('deleted', 'Team', $teamId, $teamName, 'warning', 
                                 'Team eliminado (ya no existe en BD externa)', $configId);
                    error_log("[SyncJob] Team eliminado: {$teamId} - {$teamName}");
                }
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error eliminando teams: ' . $e->getMessage());
        }
    }
    
    /**
     * PASO 4: Sincronizar Usuarios - VERSIÓN OPTIMIZADA
     */
    private function syncUsuarios(array $usuariosExternos, array $afiliadosExternos, array $rolesExternos, string $configId, array &$summary): void
    {
        error_log('[SyncJob] === PASO 4: Sincronizando Usuarios (VERSIÓN OPTIMIZADA) ===');
        error_log('[SyncJob] Total usuarios a procesar: ' . count($usuariosExternos));
        
        // Crear mapa de afiliados para búsqueda rápida
        $mapaAfiliados = [];
        foreach ($afiliadosExternos as $afiliado) {
            if (!empty($afiliado['licencia'])) {
                $mapaAfiliados[$afiliado['licencia']] = $afiliado;
            }
        }
        
        // Usar los nombres originales de roles
        $idsExternos = [];
        
        // Procesar en lotes más pequeños para evitar timeout
        $loteSize = 50; // Reducido de 100 a 50
        $totalUsuarios = count($usuariosExternos);
        $lotes = array_chunk($usuariosExternos, $loteSize);
        $loteActual = 1;
        $totalLotes = count($lotes);
        
        error_log("[SyncJob] Procesando en {$totalLotes} lotes de máximo {$loteSize} usuarios");
        
        foreach ($lotes as $lote) {
            error_log("[SyncJob] Procesando lote {$loteActual}/{$totalLotes} (" . count($lote) . " usuarios)");
            
            foreach ($lote as $usuario) {
                try {
                    // VALIDACIÓN: campos obligatorios
                    if (empty($usuario['id'])) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario con ID NULL o vacío";
                        $this->addIncidencia('validation_error', 'User', null, $usuario['username'] ?? 'Desconocido', $mensaje);
                        $this->addLog('error', 'User', null, $usuario['username'] ?? 'Desconocido', 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    if (empty($usuario['idAfiliados'])) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$usuario['id']}': campo idAfiliados es NULL o vacío";
                        $this->addIncidencia('validation_error', 'User', $usuario['id'], $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $usuario['id'], $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    if (empty($usuario['puesto'])) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$usuario['id']}': campo puesto es NULL o vacío";
                        $this->addIncidencia('validation_error', 'User', $usuario['id'], $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $usuario['id'], $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    $userId = $usuario['id'];
                    $idsExternos[] = $userId;
                    
                    // Verificar que el Team (afiliado) existe
                    $teamId = $usuario['idAfiliados'];
                    $team = $this->entityManager->getEntityById('Team', $teamId);
                    
                    if (!$team) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$userId}' tiene asignado el afiliado '{$teamId}' que no existe en el sistema";
                        $this->addIncidencia('missing_team', 'User', $userId, $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $userId, $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    // Obtener zona del afiliado para asignar CLA
                    if (!isset($mapaAfiliados[$teamId])) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$userId}': no se encontró información del afiliado '{$teamId}' en los datos consultados";
                        $this->addIncidencia('missing_team', 'User', $userId, $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $userId, $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    $zona = (int)$mapaAfiliados[$teamId]['zona'];
                    $claId = 'CLA' . $zona;
                    
                    // Verificar que el CLA existe
                    $cla = $this->entityManager->getEntityById('Team', $claId);
                    if (!$cla) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$userId}': CLA '{$claId}' no existe (zona: {$zona})";
                        $this->addIncidencia('missing_team', 'User', $userId, $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $userId, $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    // Usar el nombre de rol original sin cambios
                    $nombreRol = $usuario['puesto'];
                    $rol = $this->entityManager->getRDBRepository('Role')
                        ->where(['name' => $nombreRol])
                        ->findOne();
                    
                    if (!$rol) {
                        $summary['users']['errors']++;
                        $mensaje = "Usuario '{$userId}' usa el rol '{$usuario['puesto']}' que no existe en el sistema";
                        $this->addIncidencia('missing_role', 'User', $userId, $usuario['username'], $mensaje);
                        $this->addLog('error', 'User', $userId, $usuario['username'], 'error', $mensaje, $configId);
                        continue;
                    }
                    
                    // ========== LÓGICA DE ACTUALIZACIÓN INTELIGENTE ==========
                    
                    // Crear o actualizar usuario
                    $user = $this->entityManager->getEntityById('User', $userId);
                    $isNew = !$user;
                    
                    if (!$user) {
                        // USUARIO NUEVO - Crear con todos los datos
                        $user = $this->entityManager->getNewEntity('User');
                        $user->set('id', $userId);
                        
                        $user->set([
                            'firstName' => $usuario['nombre'],
                            'lastName' => $usuario['apellidoP'],
                            'userName' => $usuario['username'],
                            'emailAddress' => $usuario['email'] ?? '',
                            'phoneNumber' => $usuario['telMovil'] ?? '',
                            'type' => 'regular',
                            'isActive' => true,
                            'defaultTeamId' => $teamId,
                            'password' => password_hash($usuario['password'], PASSWORD_DEFAULT)
                        ]);
                        
                        $this->entityManager->saveEntity($user);
                        $summary['users']['created']++;
                        error_log("[SyncJob] Usuario creado: {$userId}");
                        
                    } else {
                        // USUARIO EXISTENTE - Verificar cambios antes de actualizar
                        $needsUpdate = false;
                        $changes = [];
                        
                        // Comparar cada campo individualmente
                        if ($user->get('firstName') !== $usuario['nombre']) {
                            $user->set('firstName', $usuario['nombre']);
                            $changes[] = 'nombre';
                            $needsUpdate = true;
                        }
                        
                        if ($user->get('lastName') !== $usuario['apellidoP']) {
                            $user->set('lastName', $usuario['apellidoP']);
                            $changes[] = 'apellido';
                            $needsUpdate = true;
                        }
                        
                        if ($user->get('userName') !== $usuario['username']) {
                            $user->set('userName', $usuario['username']);
                            $changes[] = 'username';
                            $needsUpdate = true;
                        }
                        
                        $emailExterno = $usuario['email'] ?? '';
                        if ($user->get('emailAddress') !== $emailExterno) {
                            $user->set('emailAddress', $emailExterno);
                            $changes[] = 'email';
                            $needsUpdate = true;
                        }
                        
                        $telefonoExterno = $usuario['telMovil'] ?? '';
                        if ($user->get('phoneNumber') !== $telefonoExterno) {
                            $user->set('phoneNumber', $telefonoExterno);
                            $changes[] = 'teléfono';
                            $needsUpdate = true;
                        }
                        
                        if ($user->get('defaultTeamId') !== $teamId) {
                            $user->set('defaultTeamId', $teamId);
                            $changes[] = 'team por defecto';
                            $needsUpdate = true;
                        }
                        
                        if ($needsUpdate) {
                            $this->entityManager->saveEntity($user);
                            $summary['users']['updated']++;
                            error_log("[SyncJob] Usuario actualizado: {$userId} - Campos: " . implode(', ', $changes));
                        } else {
                            $summary['users']['no_changes']++;
                            // Log opcional para debugging
                            // error_log("[SyncJob] Usuario sin cambios: {$userId}");
                        }
                    }
                    
                    // ========== GESTIÓN DE RELACIONES (Teams y Roles) ==========
                    
                    // Asignar ambos teams: afiliado + CLA
                    $teamRelation = $this->entityManager->getRDBRepository('User')
                        ->getRelation($user, 'teams');
                    
                    // Verificar y actualizar relaciones de teams
                    $currentTeams = $teamRelation->find();
                    $currentTeamIds = [];
                    foreach ($currentTeams as $currentTeam) {
                        $currentTeamIds[] = $currentTeam->getId();
                    }
                    
                    $requiredTeamIds = [$teamId, $claId];
                    $needsTeamUpdate = false;
                    
                    // Verificar si los teams requeridos ya están asignados
                    foreach ($requiredTeamIds as $requiredTeamId) {
                        if (!in_array($requiredTeamId, $currentTeamIds)) {
                            $needsTeamUpdate = true;
                            break;
                        }
                    }
                    
                    // Verificar si hay teams extra que no deberían estar
                    foreach ($currentTeamIds as $currentTeamId) {
                        if (!in_array($currentTeamId, $requiredTeamIds)) {
                            $needsTeamUpdate = true;
                            break;
                        }
                    }
                    
                    if ($needsTeamUpdate) {
                        // Eliminar todas las relaciones existentes
                        foreach ($currentTeams as $currentTeam) {
                            $teamRelation->unrelate($currentTeam);
                        }
                        
                        // Agregar las nuevas relaciones
                        $teamRelation->relate($team);
                        $teamRelation->relate($cla);
                        
                        if (!$isNew) {
                            error_log("[SyncJob] Teams actualizados para usuario: {$userId}");
                        }
                    }
                    
                    // ========== GESTIÓN INTELIGENTE DE ROLES ==========
                    // Sincronizar solo roles importados, mantener roles no importados
                    $roleRelation = $this->entityManager->getRDBRepository('User')
                        ->getRelation($user, 'roles');
                    
                    $currentRoles = $roleRelation->find();
                    $needsRoleUpdate = false;
                    
                    // Separar roles en dos grupos: roles importados y roles no importados
                    $rolesImportados = $rolesExternos; // Los roles que vienen de la BD externa
                    $rolesActuales = [];
                    $rolesNoImportados = [];
                    
                    foreach ($currentRoles as $currentRole) {
                        $nombreRolActual = $currentRole->get('name');
                        $rolesActuales[$currentRole->getId()] = $nombreRolActual;
                        
                        // Si el rol actual NO está en la lista de roles importados, es un rol no importado
                        if (!in_array($nombreRolActual, $rolesImportados)) {
                            $rolesNoImportados[$currentRole->getId()] = $currentRole;
                        }
                    }
                    
                    // Verificar si el rol requerido (de la BD externa) ya está asignado
                    $rolRequeridoAsignado = false;
                    foreach ($currentRoles as $currentRole) {
                        if ($currentRole->getId() === $rol->getId()) {
                            $rolRequeridoAsignado = true;
                            break;
                        }
                    }
                    
                    // Si el rol requerido no está asignado O hay otros roles importados que ya no aplican
                    $rolesImportadosActuales = array_intersect($rolesActuales, $rolesImportados);
                    if (!$rolRequeridoAsignado || count($rolesImportadosActuales) > 1 || (count($rolesImportadosActuales) === 1 && !$rolRequeridoAsignado)) {
                        $needsRoleUpdate = true;
                        
                        // Eliminar solo los roles importados (excepto el rol no importado que queremos mantener)
                        foreach ($currentRoles as $currentRole) {
                            $nombreRolActual = $currentRole->get('name');
                            // Solo eliminar si es un rol importado y NO es el rol que queremos asignar
                            if (in_array($nombreRolActual, $rolesImportados) && $currentRole->getId() !== $rol->getId()) {
                                $roleRelation->unrelate($currentRole);
                                error_log("[SyncJob] Rol eliminado para usuario {$userId}: {$nombreRolActual}");
                            }
                        }
                        
                        // Agregar el rol requerido si no está asignado
                        if (!$rolRequeridoAsignado) {
                            $roleRelation->relate($rol);
                            error_log("[SyncJob] Rol agregado para usuario {$userId}: {$nombreRol}");
                        }
                        
                        if (!$isNew) {
                            error_log("[SyncJob] Roles sincronizados para usuario: {$userId} - Se agregó: {$nombreRol}, se mantuvieron roles no importados: " . count($rolesNoImportados));
                        }
                    }
                    
                    // Log de roles no importados mantenidos
                    if (count($rolesNoImportados) > 0 && $needsRoleUpdate) {
                        $nombresRolesNoImportados = implode(', ', array_values(array_map(function($r) { return $r->get('name'); }, $rolesNoImportados)));
                        error_log("[SyncJob] Usuario {$userId} mantuvo roles no importados: {$nombresRolesNoImportados}");
                    }
                    
                    // Log de progreso cada 25 usuarios
                    $procesados = $summary['users']['created'] + $summary['users']['updated'] + $summary['users']['no_changes'];
                    if ($procesados % 25 == 0) {
                        error_log("[SyncJob] Progreso: {$procesados}/{$totalUsuarios} usuarios procesados");
                    }
                    
                } catch (\Exception $e) {
                    $summary['users']['errors']++;
                    $mensaje = "Error procesando usuario: " . $e->getMessage();
                    $this->addIncidencia('sync_error', 'User', $usuario['id'] ?? null, $usuario['username'] ?? 'Desconocido', $mensaje);
                    error_log("[SyncJob] {$mensaje}");
                }
            }
            
            // Forzar liberación de memoria después de cada lote
            gc_collect_cycles();
            
            $loteActual++;
        }
        
        error_log("[SyncJob] Usuarios procesados: Creados={$summary['users']['created']}, Actualizados={$summary['users']['updated']}, Sin cambios={$summary['users']['no_changes']}, Errores={$summary['users']['errors']}");
        
        // Desactivar usuarios que ya no existen (solo de roles gestionados)
        $this->disableRemovedUsers($idsExternos, $rolesExternos, $configId, $summary);
    }
    
    /**
     * Desactivar usuarios que ya no existen en BD externa
     * Solo afecta usuarios con roles que están siendo gestionados
     */
    private function disableRemovedUsers(array $idsExternos, array $rolesGestionados, string $configId, array &$summary): void
    {
        try {
            $allUsers = $this->entityManager->getRDBRepository('User')
                ->where(['type' => 'regular', 'isActive' => true])
                ->find();
            
            foreach ($allUsers as $user) {
                $userId = $user->getId();
                
                // Obtener roles del usuario
                $userRoles = $this->entityManager->getRDBRepository('User')
                    ->getRelation($user, 'roles')
                    ->find();
                
                // Verificar si el usuario tiene algún rol gestionado
                $tieneRolGestionado = false;
                foreach ($userRoles as $role) {
                    if (in_array($role->get('name'), $rolesGestionados)) {
                        $tieneRolGestionado = true;
                        break;
                    }
                }
                
                // Solo desactivar si tiene rol gestionado y no está en la lista externa
                if ($tieneRolGestionado && !in_array($userId, $idsExternos)) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $summary['users']['disabled']++;
                    $this->addLog('disabled', 'User', $userId, $user->get('userName'), 'warning', 
                                 'Usuario desactivado (ya no existe en BD externa)', $configId);
                    error_log("[SyncJob] Usuario desactivado: {$userId} - " . $user->get('userName'));
                }
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error desactivando usuarios: ' . $e->getMessage());
        }
    }
    
    /**
     * Agregar incidencia para notificar por email
     */
    private function addIncidencia(string $tipo, string $entityType, ?string $entityId, string $entityName, string $mensaje): void
    {
        $this->incidencias[] = [
            'tipo' => $tipo,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'entityName' => $entityName,
            'mensaje' => $mensaje,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Enviar email con todas las incidencias
     */
    private function sendIncidenciasEmail(string $email, array $summary): void
    {
        try {
            error_log("[SyncJob] Preparando envío de email de incidencias a: {$email}");
            error_log("[SyncJob] Total incidencias a reportar: " . count($this->incidencias));
            
            // TEMPORAL: Solo loguear las incidencias en lugar de enviar email
            // Esto nos permite continuar con la sincronización mientras resolvemos el problema del email
            
            if (count($this->incidencias) > 0) {
                error_log("[SyncJob] === INCIDENCIAS DETECTADAS ===");
                foreach ($this->incidencias as $index => $incidencia) {
                    error_log("[SyncJob] Incidencia {$index}: {$incidencia['entityType']} - {$incidencia['entityName']} - {$incidencia['mensaje']}");
                }
                error_log("[SyncJob] === FIN DE INCIDENCIAS ===");
            }
            
            error_log("[SyncJob] (Email desactivado temporalmente) Resumen: " . 
                    "Roles: {$summary['roles']['created']} creados, " .
                    "Teams: {$summary['teams']['created']} creados, " .
                    "Usuarios: {$summary['users']['created']} creados, {$summary['users']['updated']} actualizados");
            
            // Para implementar el envío de email más adelante, necesitaremos:
            // 1. Crear una plantilla de email en EspoCRM
            // 2. Usar el servicio de notificaciones
            // 3. O configurar correctamente el EmailSender
            
        } catch (\Exception $e) {
            error_log('[SyncJob] Error en sistema de notificación: ' . $e->getMessage());
            // No detenemos la sincronización por un error de notificación
        }
    }
    
    /**
     * Obtener etiqueta legible para tipo de incidencia
     */
    private function getTipoLabel(string $tipo): string
    {
        $labels = [
            'validation_error' => 'Errores de Validación',
            'missing_team' => 'Equipos No Encontrados',
            'missing_role' => 'Roles No Encontrados',
            'missing_zona' => 'Zonas Inválidas',
            'sync_error' => 'Errores de Sincronización'
        ];
        
        return $labels[$tipo] ?? ucfirst($tipo);
    }
    
    /**
     * Agregar log de sincronización
     */
    private function addLog(string $action, string $entityType, ?string $entityId, string $entityName, string $status, string $message, ?string $configId = null): void
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
            error_log('[SyncJob] Error creando log: ' . $e->getMessage());
        }
    }
    
    /**
     * Limpiar logs antiguos (más de 30 días)
     */
    private function cleanOldLogs(): void
    {
        try {
            $date30DaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $oldLogs = $this->entityManager->getRDBRepository('SyncLog')
                ->where(['syncDate<' => $date30DaysAgo])
                ->find();
            
            $count = 0;
            foreach ($oldLogs as $log) {
                $this->entityManager->removeEntity($log);
                $count++;
            }
            
            if ($count > 0) {
                error_log("[SyncJob] Logs antiguos eliminados: {$count}");
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error limpiando logs: ' . $e->getMessage());
        }
    }
    
    /**
     * Actualizar estado de la configuración
     */
    private function updateConfigStatus(string $configId, string $status): void
    {
        try {
            $config = $this->entityManager->getEntityById('ExternalDbConfig', $configId);
            if ($config) {
                $config->set([
                    'lastSync' => date('Y-m-d H:i:s'),
                    'lastSyncStatus' => $status
                ]);
                $this->entityManager->saveEntity($config);
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error actualizando status: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener configuración activa desencriptada
     */
    private function getActiveConfig(): ?array
    {
        try {
            $config = $this->entityManager
                ->getRDBRepository('ExternalDbConfig')
                ->where(['isActive' => true])
                ->order('createdAt', 'DESC')
                ->findOne();
            
            if (!$config) {
                return null;
            }
            
            return [
                'id' => $config->getId(),
                'name' => $config->get('name'),
                'host' => $this->decrypt($config->get('host')),
                'port' => $config->get('port'),
                'database' => $this->decrypt($config->get('database')),
                'username' => $this->decrypt($config->get('username')),
                'password' => $this->decrypt($config->get('password')),
                'notificationEmail' => $config->get('notificationEmail')
            ];
        } catch (\Exception $e) {
            error_log('[SyncJob] Error obteniendo config: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Desencriptar valor
     */
    private function decrypt(string $encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return '';
        }
        
        try {
            $config = $this->injectableFactory->create('Espo\\Core\\Utils\\Config');
            $passwordSalt = $config->get('passwordSalt');
            $siteUrl = $config->get('siteUrl');
            $secretKey = hash('sha256', $passwordSalt . $siteUrl, true);
            
            $data = base64_decode($encryptedValue, true);
            if ($data === false) {
                return $encryptedValue;
            }
            
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            
            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);
            
            return $decrypted !== false ? $decrypted : $encryptedValue;
        } catch (\Exception $e) {
            error_log('[SyncJob] Error desencriptando: ' . $e->getMessage());
            return $encryptedValue;
        }
    }
    
    /**
     * Conectar a base de datos externa
     */
    private function connectToExternalDb(array $config): ?PDO
    {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
            
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10
            ]);
            
            return $pdo;
        } catch (PDOException $e) {
            error_log('[SyncJob] Error conexión: ' . $e->getMessage());
            return null;
        }
    }
}