<?php
namespace Espo\Modules\Sincronizacion\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\PasswordHash;
use Espo\Modules\Sincronizacion\Handlers\TeamHandler;
use Espo\Modules\Sincronizacion\Handlers\UserHandler;
use Espo\Modules\Sincronizacion\Handlers\ImageHandler;
use Espo\Modules\Sincronizacion\Traits\Loggable;
use PDO;
use PDOException;

class SincronizarDatosExternos implements JobDataLess
{
    use Loggable;

    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    
    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(): void
    {
        $config = null;
        $startTime = microtime(true);
        
        try {
            set_time_limit(0);
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 0);
            
            $this->log('info', 'Job', null, 'SincronizarDatosExternos', 'success', 
                    'Job iniciado', null);
            
            $config = $this->getActiveConfig();
            if (!$config) {
                $this->log('error', 'Config', null, 'SincronizarDatosExternos', 'error',
                        'No hay configuración activa de BD externa', null);
                return;
            }
            
            $this->log('info', 'Config', $config['id'], $config['name'], 'success',
                    "Usando configuración: {$config['name']}", $config['id']);
            
            $pdo = $this->connectToExternalDb($config);
            if (!$pdo) {
                $this->log('error', 'Config', $config['id'], $config['name'], 'error',
                        'No se pudo conectar a la BD externa', $config['id']);
                $this->updateConfigStatus($config['id'], 'error');
                return;
            }
            
            $sqlUsuarios = "SELECT id, idAfiliados, nombre, apellidoM, apellidoP, username, password, email, telMovil, puesto, fotoPath 
                        FROM usuarios 
                        WHERE isActive = 1 AND idAfiliados IS NOT NULL";
            $stmtUsuarios = $pdo->prepare($sqlUsuarios);
            $stmtUsuarios->execute();
            $usuariosExternos = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
            
            $sqlUsuariosInactivos = "SELECT id, idAfiliados, nombre, apellidoM, apellidoP, username, password, email, telMovil, puesto, fotoPath 
                                FROM usuarios 
                                WHERE isActive = 0 AND idAfiliados IS NOT NULL";
            $stmtUsuariosInactivos = $pdo->prepare($sqlUsuariosInactivos);
            $stmtUsuariosInactivos->execute();
            $usuariosInactivos = $stmtUsuariosInactivos->fetchAll(PDO::FETCH_ASSOC);
            
            $sqlAfiliados = "SELECT licencia, nombre, zona 
                            FROM afiliados 
                            WHERE isActive = 1
                            AND (suspendida = 0 OR suspendida IS NULL)";
            $stmtAfiliados = $pdo->prepare($sqlAfiliados);
            $stmtAfiliados->execute();
            $afiliadosExternos = $stmtAfiliados->fetchAll(PDO::FETCH_ASSOC);
            
            $sqlRoles = "SELECT DISTINCT puesto 
                        FROM usuarios 
                        WHERE puesto IS NOT NULL 
                        ORDER BY puesto";
            $stmtRoles = $pdo->prepare($sqlRoles);
            $stmtRoles->execute();
            $rolesExternos = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);
            
            $pdo = null;
            
            $this->log('info', 'Datos', null, 'Consulta', 'success',
                    "Usuarios activos: " . count($usuariosExternos) . 
                    ", Inactivos: " . count($usuariosInactivos) . 
                    ", Afiliados: " . count($afiliadosExternos) . 
                    ", Roles: " . count($rolesExternos), $config['id']);
            
            $passwordHash = $this->injectableFactory->create(PasswordHash::class);
            $imageHandler = new ImageHandler($this->entityManager);
            $teamHandler = new TeamHandler($this->entityManager);
            $userHandler = new UserHandler($this->entityManager, $imageHandler, $teamHandler, $passwordHash);
            
            $summary = [
                'roles' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'clas' => ['created' => 0, 'existing' => 0, 'errors' => 0],
                'teams' => ['created' => 0, 'updated' => 0, 'deleted' => 0, 'no_changes' => 0, 'skipped' => 0, 'errors' => 0],
                'users' => ['created' => 0, 'updated' => 0, 'disabled' => 0, 'errors' => 0, 'skipped' => 0, 'no_changes' => 0]
            ];
            
            $this->syncRoles($rolesExternos, $config['id'], $summary);
            
            $teamHandler->syncCLAs($config['id'], $summary);
            
            $teamHandler->syncAfiliados($afiliadosExternos, $config['id'], $summary);
            
            $userHandler->syncUsuarios($usuariosExternos, $usuariosInactivos, $afiliadosExternos, $rolesExternos, $config['id'], $summary);
            
            $this->cleanOldLogs();
            
            $hasErrors = $summary['roles']['errors'] > 0 || 
                        $summary['clas']['errors'] > 0 || 
                        $summary['teams']['errors'] > 0 || 
                        $summary['users']['errors'] > 0;
            
            $status = $hasErrors ? 'error' : 'success';
            
            $this->updateConfigStatus($config['id'], $status);
            
            $elapsed = round(microtime(true) - $startTime, 2);
            
            $resumenMsg = sprintf(
                "Roles - Creados: %d | Existentes: %d | Errores: %d | " .
                "CLAs - Creados: %d | Existentes: %d | " .
                "Oficinas - Creadas: %d | Actualizadas: %d | Sin cambios: %d | Eliminadas: %d | Omitidas: %d | Errores: %d | " .
                "Usuarios - Creados: %d | Actualizados: %d | Desactivados: %d | Sin cambios: %d | Omitidos: %d | Errores: %d | " .
                "Tiempo: %ss",
                $summary['roles']['created'], $summary['roles']['existing'], $summary['roles']['errors'],
                $summary['clas']['created'], $summary['clas']['existing'],
                $summary['teams']['created'], $summary['teams']['updated'], $summary['teams']['no_changes'] ?? 0, 
                $summary['teams']['deleted'], $summary['teams']['skipped'] ?? 0, $summary['teams']['errors'],
                $summary['users']['created'], $summary['users']['updated'], $summary['users']['disabled'], 
                $summary['users']['no_changes'], $summary['users']['skipped'], $summary['users']['errors'],
                $elapsed
            );
            
            $this->log('info', 'Resumen', null, 'Sincronización Completa', $status,
                    $resumenMsg, $config['id']);
            
        } catch (\Exception $e) {
            $errorMsg = 'Error crítico: ' . $e->getMessage();
            error_log('[SyncJob] ' . $errorMsg);
            
            if ($config) {
                $this->log('error', 'Job', $config['id'], 'SincronizarDatosExternos', 'error',
                        $errorMsg, $config['id']);
                $this->updateConfigStatus($config['id'], 'error');
            } else {
                $this->log('error', 'Job', null, 'SincronizarDatosExternos', 'error',
                        $errorMsg, null);
            }
        }
    }
    
    private function syncRoles(array $rolesExternos, string $configId, array &$summary): void
    {
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
                    $this->log('created', 'Role', $rol->getId(), $nombreRol, 'success',
                              "Rol creado", $configId);
                } else {
                    $summary['roles']['existing']++;
                }
                
            } catch (\Exception $e) {
                $summary['roles']['errors']++;
                $this->log('error', 'Role', null, $puestoOriginal, 'error',
                          "Error creando rol: " . $e->getMessage(), $configId);
            }
        }
        
        $this->log('info', 'Role', null, 'Resumen Roles', 'success',
                  "Creados: {$summary['roles']['created']} | Existentes: {$summary['roles']['existing']} | Errores: {$summary['roles']['errors']}", $configId);
    }
    
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
                $this->log('info', 'Mantenimiento', null, 'Limpieza Logs', 'success',
                          "Logs antiguos eliminados: {$count}", null);
            }
        } catch (\Exception $e) {
            $this->log('error', 'Mantenimiento', null, 'Limpieza Logs', 'error',
                      "Error limpiando logs: " . $e->getMessage(), null);
        }
    }
    
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
        }
    }
    
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
            return null;
        }
    }
    
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
            return $encryptedValue;
        }
    }
    
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
            return null;
        }
    }
}