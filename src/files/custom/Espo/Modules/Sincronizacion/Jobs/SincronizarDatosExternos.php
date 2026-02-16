<?php

namespace Espo\Modules\Sincronizacion\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\PasswordHash;
use Espo\Modules\Sincronizacion\Handlers\TeamHandler;
use Espo\Modules\Sincronizacion\Handlers\UserHandler;
use Espo\Modules\Sincronizacion\Handlers\ImageHandler;
use PDO;
use PDOException;

/**
 * Job programado que sincroniza usuarios y teams desde una base de datos externa
 * 
 * REFACTORIZADO - Versión modular con separación de responsabilidades
 */
class SincronizarDatosExternos implements JobDataLess
{
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    private array $incidencias = [];
    
    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(): void
    {
        $GLOBALS['log']->info('[SyncJob] ========== INICIANDO SINCRONIZACIÓN ==========');
        error_log('[SyncJob] ========== INICIANDO SINCRONIZACIÓN ==========');
        
        try {
            // Aumentar límites de ejecución
            set_time_limit(0);
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 0);
            
            // 1. Obtener configuración activa
            $GLOBALS['log']->info('[SyncJob] Obteniendo configuración activa...');
            $config = $this->getActiveConfig();
            if (!$config) {
                $GLOBALS['log']->error('[SyncJob] No hay configuración activa de BD externa');
                error_log('[SyncJob] No hay configuración activa de BD externa');
                return;
            }
            
            $GLOBALS['log']->info("[SyncJob] Usando configuración: {$config['name']}");
            error_log("[SyncJob] Usando configuración: {$config['name']}");
            
            // 2. Conectar a la base de datos externa
            $GLOBALS['log']->info('[SyncJob] Conectando a BD externa...');
            error_log('[SyncJob] Conectando a BD externa...');
            
            $pdo = $this->connectToExternalDb($config);
            if (!$pdo) {
                $this->updateConfigStatus($config['id'], 'error');
                $GLOBALS['log']->error('[SyncJob] No se pudo conectar a la BD externa');
                error_log('[SyncJob] No se pudo conectar a la BD externa');
                return;
            }
            
            $GLOBALS['log']->info('[SyncJob] Conexión a BD externa exitosa');
            error_log('[SyncJob] Conexión a BD externa exitosa');
            
            // 3. Consultar datos de BD externa
            $GLOBALS['log']->info('[SyncJob] Consultando datos de BD externa...');
            error_log('[SyncJob] Consultando datos de BD externa...');
            
            // Consulta actualizada de usuarios ACTIVOS con apellidoM y fotoPath
            $GLOBALS['log']->info('[SyncJob] Consultando usuarios ACTIVOS...');
            $sqlUsuarios = "SELECT id, idAfiliados, nombre, apellidoM, apellidoP, username, password, email, telMovil, puesto, fotoPath 
                           FROM usuarios 
                           WHERE isActive = 1 AND idAfiliados IS NOT NULL";
            $stmtUsuarios = $pdo->prepare($sqlUsuarios);
            $stmtUsuarios->execute();
            $usuariosExternos = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
            $GLOBALS['log']->info('[SyncJob] Usuarios ACTIVOS obtenidos: ' . count($usuariosExternos));
            
            // Consulta de usuarios INACTIVOS (para desactivarlos en EspoCRM)
            $GLOBALS['log']->info('[SyncJob] Consultando usuarios INACTIVOS...');
            $sqlUsuariosInactivos = "SELECT id, idAfiliados, nombre, apellidoM, apellidoP, username, password, email, telMovil, puesto, fotoPath 
                           FROM usuarios 
                           WHERE isActive = 0 AND idAfiliados IS NOT NULL";
            $stmtUsuariosInactivos = $pdo->prepare($sqlUsuariosInactivos);
            $stmtUsuariosInactivos->execute();
            $usuariosInactivos = $stmtUsuariosInactivos->fetchAll(PDO::FETCH_ASSOC);
            $GLOBALS['log']->info('[SyncJob] Usuarios INACTIVOS obtenidos: ' . count($usuariosInactivos));
            
            // Consulta de afiliados
            $GLOBALS['log']->info('[SyncJob] Consultando afiliados...');
            $sqlAfiliados = "SELECT licencia, nombre, zona 
                            FROM afiliados 
                            WHERE isActive = 1
                            AND (suspendida = 0 OR suspendida IS NULL)";
            $stmtAfiliados = $pdo->prepare($sqlAfiliados);
            $stmtAfiliados->execute();
            $afiliadosExternos = $stmtAfiliados->fetchAll(PDO::FETCH_ASSOC);
            $GLOBALS['log']->info('[SyncJob] Afiliados obtenidos: ' . count($afiliadosExternos));
            
            // Consulta de roles distintos
            $GLOBALS['log']->info('[SyncJob] Consultando roles...');
            $sqlRoles = "SELECT DISTINCT puesto 
                        FROM usuarios 
                        WHERE puesto IS NOT NULL 
                        ORDER BY puesto";
            $stmtRoles = $pdo->prepare($sqlRoles);
            $stmtRoles->execute();
            $rolesExternos = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);
            $GLOBALS['log']->info('[SyncJob] Roles obtenidos: ' . count($rolesExternos));
            
            $pdo = null; // Cerrar conexión
            
            $mensaje = '[SyncJob] Datos obtenidos: ' . count($usuariosExternos) . ' usuarios activos, ' . 
                     count($usuariosInactivos) . ' usuarios inactivos, ' .
                     count($afiliadosExternos) . ' afiliados, ' . count($rolesExternos) . ' roles';
            $GLOBALS['log']->info($mensaje);
            error_log($mensaje);
            
            // 4. Inicializar handlers
            $GLOBALS['log']->info('[SyncJob] Inicializando handlers...');
            
            $passwordHash = $this->injectableFactory->create(PasswordHash::class);
            $imageHandler = new ImageHandler($this->entityManager);
            $teamHandler = new TeamHandler($this->entityManager);
            $userHandler = new UserHandler($this->entityManager, $imageHandler, $teamHandler, $passwordHash);
            
            $GLOBALS['log']->info('[SyncJob] Handlers inicializados correctamente');
            
            // 5. Ejecutar sincronización en ORDEN ESPECÍFICO
            $summary = [
                'roles' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'clas' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'teams' => ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0],
                'users' => ['created' => 0, 'updated' => 0, 'disabled' => 0, 'errors' => 0, 'skipped' => 0, 'no_changes' => 0]
            ];
            
            error_log('[SyncJob] ========== ORDEN DE SINCRONIZACIÓN ==========');
            error_log('[SyncJob] 1. Roles - Crear roles que no existan');
            error_log('[SyncJob] 2. CLAs - Verificar que todos los CLAs existan');
            error_log('[SyncJob] 3. Oficinas - Crear/Actualizar/Eliminar según BD externa');
            error_log('[SyncJob] 4. Usuarios - Crear/Actualizar según BD externa');
            error_log('[SyncJob] ================================================');
            
            // PASO 1: Sincronizar Roles
            error_log('[SyncJob] >>> PASO 1: Sincronizando Roles...');
            $this->syncRoles($rolesExternos, $config['id'], $summary);
            
            // PASO 2: Sincronizar CLAs
            error_log('[SyncJob] >>> PASO 2: Sincronizando CLAs...');
            $teamHandler->syncCLAs($config['id'], $summary);
            
            // PASO 3: Sincronizar Oficinas (crea, actualiza Y ELIMINA)
            // IMPORTANTE: Al eliminar oficinas, primero desactiva usuarios de esa oficina
            error_log('[SyncJob] >>> PASO 3: Sincronizando Oficinas...');
            $teamHandler->syncAfiliados($afiliadosExternos, $config['id'], $summary);
            
            // PASO 4: Sincronizar Usuarios (crea y actualiza, NO elimina)
            // Los usuarios obsoletos se desactivan, no se eliminan
            error_log('[SyncJob] >>> PASO 4: Sincronizando Usuarios...');
            $userHandler->syncUsuarios($usuariosExternos, $usuariosInactivos, $afiliadosExternos, $rolesExternos, $config['id'], $summary);
            
            // 6. Recopilar incidencias de todos los handlers
            $this->incidencias = array_merge(
                $this->incidencias,
                $teamHandler->getIncidencias(),
                $userHandler->getIncidencias()
            );
            
            // 7. Limpiar logs antiguos
            $this->cleanOldLogs();
            
            // 8. Determinar estado final
            $status = 'success';
            $hasErrors = $summary['roles']['errors'] > 0 || 
                        $summary['clas']['errors'] > 0 || 
                        $summary['teams']['errors'] > 0 || 
                        $summary['users']['errors'] > 0;
            
            if ($hasErrors || count($this->incidencias) > 0) {
                $status = 'warning';
            }
            
            $this->updateConfigStatus($config['id'], $status);
            
            // 9. Enviar email con incidencias si las hay
            if (count($this->incidencias) > 0 && !empty($config['notificationEmail'])) {
                $this->sendIncidenciasEmail($config['notificationEmail'], $summary);
            }
            
            // 10. Log resumen
            error_log('[SyncJob] ========== SINCRONIZACIÓN COMPLETADA ==========');
            error_log('[SyncJob] Roles - Creados: ' . $summary['roles']['created'] . ' | Existentes: ' . $summary['roles']['existing'] . ' | Errores: ' . $summary['roles']['errors']);
            error_log('[SyncJob] CLAs - Creados: ' . $summary['clas']['created'] . ' | Existentes: ' . $summary['clas']['existing']);
            error_log('[SyncJob] Teams - Creados: ' . $summary['teams']['created'] . ' | Actualizados: ' . $summary['teams']['updated'] . ' | Eliminados: ' . $summary['teams']['deleted'] . ' | Errores: ' . $summary['teams']['errors']);
            error_log('[SyncJob] Users - Creados: ' . $summary['users']['created'] . ' | Actualizados: ' . $summary['users']['updated'] . ' | Desactivados: ' . $summary['users']['disabled'] . ' | Sin cambios: ' . $summary['users']['no_changes'] . ' | Errores: ' . $summary['users']['errors']);
            error_log('[SyncJob] Total incidencias notificadas: ' . count($this->incidencias));
            
        } catch (\Exception $e) {
            $mensaje = '[SyncJob] Error crítico: ' . $e->getMessage();
            $trace = '[SyncJob] Trace: ' . $e->getTraceAsString();
            
            error_log($mensaje);
            error_log($trace);
            
            if (isset($GLOBALS['log'])) {
                $GLOBALS['log']->error($mensaje);
                $GLOBALS['log']->error($trace);
                $GLOBALS['log']->error('[SyncJob] Archivo: ' . $e->getFile());
                $GLOBALS['log']->error('[SyncJob] Línea: ' . $e->getLine());
            }
            
            // NO re-lanzar para evitar Error 500
            // El error ya está registrado en logs
        }
    }
    
    /**
     * Sincronizar Roles
     */
    private function syncRoles(array $rolesExternos, string $configId, array &$summary): void
    {
        error_log('[SyncJob] === Sincronizando Roles ===');
        
        foreach ($rolesExternos as $puestoOriginal) {
            try {
                $nombreRol = $puestoOriginal;
                
                $rol = $this->entityManager->getRDBRepository('Role')
                    ->where(['name' => $nombreRol])
                    ->findOne();
                
                if (!$rol) {
                    $rol = $this->entityManager->getNewEntity('Role');
                    $rol->set('name', $nombreRol);
                    $this->entityManager->saveEntity($rol);
                    
                    $summary['roles']['created']++;
                    $this->addLog('created', 'Role', $rol->getId(), $nombreRol, 'success', 
                                 "Rol '{$nombreRol}' creado automáticamente", $configId);
                    error_log("[SyncJob] Rol creado: {$nombreRol}");
                } else {
                    $summary['roles']['existing']++;
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
     * Enviar email con incidencias
     */
    private function sendIncidenciasEmail(string $emailDestino, array $summary): void
    {
        try {
            error_log("[SyncJob] === INCIDENCIAS DETECTADAS ===");
            error_log("[SyncJob] Total de incidencias: " . count($this->incidencias));
            
            if (!empty($this->incidencias)) {
                $grouped = [];
                foreach ($this->incidencias as $inc) {
                    $tipo = $inc['tipo'];
                    if (!isset($grouped[$tipo])) {
                        $grouped[$tipo] = [];
                    }
                    $grouped[$tipo][] = $inc;
                }
                
                foreach ($grouped as $tipo => $items) {
                    error_log("[SyncJob] {$this->getTipoLabel($tipo)}: " . count($items) . " casos");
                    foreach ($items as $item) {
                        error_log("[SyncJob]   - {$item['entityName']}: {$item['mensaje']}");
                    }
                }
                error_log("[SyncJob] === FIN DE INCIDENCIAS ===");
            }
            
        } catch (\Exception $e) {
            error_log('[SyncJob] Error en sistema de notificación: ' . $e->getMessage());
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