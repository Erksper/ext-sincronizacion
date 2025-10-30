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
            error_log('[SyncJob] Iniciando sincronización...');
            
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
            
            // 3. Ejecutar sincronización
            $result = $this->syncAll($config, $pdo);
            
            // 4. Actualizar estado
            $status = $result['success'] ? 'success' : 'error';
            if ($result['success'] && ($result['summary']['teams']['errors'] > 0 || $result['summary']['users']['errors'] > 0)) {
                $status = 'warning';
            }
            
            $this->updateConfigStatus($config['id'], $status);
            
            // 5. Log resumen
            $summary = $result['summary'];
            error_log('[SyncJob] Completado:');
            error_log('  Teams - Creados: ' . $summary['teams']['created'] . ' | Actualizados: ' . $summary['teams']['updated'] . ' | Errores: ' . $summary['teams']['errors']);
            error_log('  Users - Creados: ' . $summary['users']['created'] . ' | Actualizados: ' . $summary['users']['updated'] . ' | Desactivados: ' . $summary['users']['disabled'] . ' | Errores: ' . $summary['users']['errors']);
            
            $pdo = null;
            
        } catch (\Exception $e) {
            error_log('[SyncJob] Error crítico: ' . $e->getMessage());
            error_log('[SyncJob] Trace: ' . $e->getTraceAsString());
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
            error_log('[SyncJob] Error obteniendo config: ' . $e->getMessage());
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
            error_log('[SyncJob] Error desencriptando: ' . $e->getMessage());
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
            error_log('[SyncJob] Error conexión: ' . $e->getMessage());
            return null;
        }
    }
    
    private function syncAll(array $config, PDO $pdo): array
    {
        $summary = [
            'teams' => ['created' => 0, 'updated' => 0, 'errors' => 0],
            'users' => ['created' => 0, 'updated' => 0, 'disabled' => 0, 'errors' => 0]
        ];
        
        try {
            // Sincronizar Teams
            $this->syncTeams($pdo, $config['id'], $summary);
            
            // Sincronizar Usuarios
            $this->syncUsers($pdo, $config['id'], $summary);
            
            // Limpiar logs antiguos
            $this->cleanOldLogs();
            
            return ['success' => true, 'summary' => $summary];
        } catch (\Exception $e) {
            error_log('[SyncJob] Error en syncAll: ' . $e->getMessage());
            return ['success' => false, 'summary' => $summary];
        }
    }
    
    private function syncTeams(PDO $pdo, string $configId, array &$summary): void
    {
        try {
            $stmt = $pdo->prepare("SELECT licencia, nombre FROM afiliados WHERE isActive = 1");
            $stmt->execute();
            $afiliados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($afiliados as $afiliado) {
                try {
                    $teamId = $afiliado['licencia'];
                    $team = $this->entityManager->getEntityById('Team', $teamId);
                    
                    if (!$team) {
                        $team = $this->entityManager->getNewEntity('Team');
                        $team->set(['id' => $teamId, 'name' => $afiliado['nombre']]);
                        $this->entityManager->saveEntity($team);
                        $summary['teams']['created']++;
                        $this->addLog('created', 'Team', $teamId, $afiliado['nombre'], 'success', 'Team creado', $configId);
                    } elseif ($team->get('name') !== $afiliado['nombre']) {
                        $team->set('name', $afiliado['nombre']);
                        $this->entityManager->saveEntity($team);
                        $summary['teams']['updated']++;
                        $this->addLog('updated', 'Team', $teamId, $afiliado['nombre'], 'success', 'Team actualizado', $configId);
                    }
                } catch (\Exception $e) {
                    $summary['teams']['errors']++;
                    $this->addLog('error', 'Team', $afiliado['licencia'] ?? null, $afiliado['nombre'] ?? 'Desconocido', 'error', $e->getMessage(), $configId);
                }
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error syncTeams: ' . $e->getMessage());
        }
    }
    
    private function syncUsers(PDO $pdo, string $configId, array &$summary): void
    {
        try {
            $asesorRole = $this->entityManager->getRDBRepository('Role')->where(['name' => 'Asesor'])->findOne();
            
            if (!$asesorRole) {
                $this->addLog('error', 'Role', null, 'Asesor', 'error', 'El rol "Asesor" no existe', $configId);
                return;
            }
            
            $stmt = $pdo->prepare("SELECT id, idAfiliados, nombre, apellidoP, username, password, email, telMovil FROM usuarios WHERE isActive = 1 AND idAfiliados IS NOT NULL");
            $stmt->execute();
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $externalUserIds = array_column($usuarios, 'id');
            
            foreach ($usuarios as $usuario) {
                try {
                    $userId = $usuario['id'];
                    $teamId = $usuario['idAfiliados'];
                    
                    $team = $this->entityManager->getEntityById('Team', $teamId);
                    if (!$team) {
                        $this->addLog('skipped', 'User', $userId, $usuario['username'], 'warning', "Team {$teamId} no encontrado", $configId);
                        continue;
                    }
                    
                    $user = $this->entityManager->getEntityById('User', $userId);
                    $isNew = !$user;
                    
                    if (!$user) {
                        $user = $this->entityManager->getNewEntity('User');
                        $user->set('id', $userId);
                    }
                    
                    $user->set([
                        'firstName' => $usuario['nombre'],
                        'lastName' => $usuario['apellidoP'],
                        'userName' => $usuario['username'],
                        'emailAddress' => $usuario['email'],
                        'phoneNumber' => $usuario['telMovil'] ?? '',
                        'type' => 'regular',
                        'isActive' => true,
                        'defaultTeamId' => $teamId,
                        'password' => password_hash($usuario['password'], PASSWORD_DEFAULT)
                    ]);
                    
                    $this->entityManager->saveEntity($user);
                    
                    $this->entityManager->getRDBRepository('User')->getRelation($user, 'teams')->massRelate([$teamId]);
                    $this->entityManager->getRDBRepository('User')->getRelation($user, 'roles')->massRelate([$asesorRole->getId()]);
                    
                    if ($isNew) {
                        $summary['users']['created']++;
                        $this->addLog('created', 'User', $userId, $usuario['username'], 'success', 'Usuario creado', $configId);
                    } else {
                        $summary['users']['updated']++;
                        $this->addLog('updated', 'User', $userId, $usuario['username'], 'success', 'Usuario actualizado', $configId);
                    }
                } catch (\Exception $e) {
                    $summary['users']['errors']++;
                    $this->addLog('error', 'User', $usuario['id'] ?? null, $usuario['username'] ?? 'Desconocido', 'error', $e->getMessage(), $configId);
                }
            }
            
            $this->disableRemovedUsers($externalUserIds, $configId, $summary);
        } catch (\Exception $e) {
            error_log('[SyncJob] Error syncUsers: ' . $e->getMessage());
        }
    }
    
    private function disableRemovedUsers(array $externalUserIds, string $configId, array &$summary): void
    {
        try {
            $espoUsers = $this->entityManager->getRDBRepository('User')->where(['type' => 'regular', 'isActive' => true])->find();
            
            foreach ($espoUsers as $user) {
                if (!in_array($user->getId(), $externalUserIds)) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    $summary['users']['disabled']++;
                    $this->addLog('disabled', 'User', $user->getId(), $user->get('userName'), 'warning', 'Usuario ya no existe en BD externa', $configId);
                }
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error disableRemovedUsers: ' . $e->getMessage());
        }
    }
    
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
    
    private function cleanOldLogs(): void
    {
        try {
            $date30DaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $oldLogs = $this->entityManager->getRDBRepository('SyncLog')->where(['syncDate<' => $date30DaysAgo])->find();
            
            foreach ($oldLogs as $log) {
                $this->entityManager->removeEntity($log);
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error limpiando logs: ' . $e->getMessage());
        }
    }
    
    private function updateConfigStatus(string $configId, string $status): void
    {
        try {
            $config = $this->entityManager->getEntityById('ExternalDbConfig', $configId);
            if ($config) {
                $config->set(['lastSync' => date('Y-m-d H:i:s'), 'lastSyncStatus' => $status]);
                $this->entityManager->saveEntity($config);
            }
        } catch (\Exception $e) {
            error_log('[SyncJob] Error actualizando status: ' . $e->getMessage());
        }
    }
}