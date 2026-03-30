<?php
namespace Espo\Modules\Sincronizacion\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Traits\Loggable;
use PDO;
use PDOException;

class SincronizarPropiedades implements JobDataLess
{
    use Loggable;

    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;

    public function __construct(
        EntityManager    $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager    = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(?array $data = null): void
    {
        $config    = null;
        $startTime = microtime(true);

        try {
            set_time_limit(0);
            ini_set('memory_limit', '1024M');

            $this->log('info', 'Job', null, 'SincronizarPropiedades', 'success',
                'Job iniciado', null);

            $config = $this->getActiveConfig();
            if (!$config) {
                $this->log('error', 'Config', null, 'SincronizarPropiedades', 'error',
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

            $this->log('info', 'Config', $config['id'], $config['name'], 'success',
                'Conexión a BD externa establecida', $config['id']);

            $syncType = $data['tipo'] ?? $this->determineSyncType();
            $this->log('info', 'Config', $config['id'], $config['name'], 'success',
                "Tipo de sincronización: {$syncType}", $config['id']);

            $summary = [
                'propiedades' => [
                    'created'    => 0,
                    'updated'    => 0,
                    'no_changes' => 0,
                    'skipped'    => 0,
                    'errors'     => 0,
                ],
                'clientes' => [
                    'created'    => 0,
                    'updated'    => 0,
                    'no_changes' => 0,
                    'skipped'    => 0,
                    'errors'     => 0,
                ],
                'lados' => [
                    'created'    => 0,
                    'updated'    => 0,
                    'no_changes' => 0,
                    'skipped'    => 0,
                    'errors'     => 0,
                ],
            ];

            // ------------------------------------------------------------------
            // 1. Propiedades
            // ------------------------------------------------------------------
            $propiedadHandler = $this->injectableFactory->create(
                'Espo\\Modules\\Sincronizacion\\Handlers\\PropiedadHandler'
            );
            $propiedadHandler->syncPropiedades($pdo, $syncType, $config['id'], $summary);

            // ------------------------------------------------------------------
            // 2. Clientes
            // ------------------------------------------------------------------
            $clienteHandler = $this->injectableFactory->create(
                'Espo\\Modules\\Sincronizacion\\Handlers\\ClienteHandler'
            );
            $clienteHandler->syncClientes($pdo, $config['id'], $summary);

            // ------------------------------------------------------------------
            // 3. Lados (asignacion)
            // ------------------------------------------------------------------
            $ladoHandler = $this->injectableFactory->create(
                'Espo\\Modules\\Sincronizacion\\Handlers\\LadoHandler'
            );
            $ladoHandler->syncLados($pdo, $config['id'], $summary);

            // Cerrar conexión
            $pdo = null;

            // ------------------------------------------------------------------
            // Resultado final
            // ------------------------------------------------------------------
            $hasErrors =
                $summary['propiedades']['errors'] > 0 ||
                $summary['clientes']['errors']    > 0 ||
                $summary['lados']['errors']       > 0;

            $status = $hasErrors ? 'error' : 'success';

            $this->updateConfigStatus($config['id'], $status);

            $elapsed    = round(microtime(true) - $startTime, 2);
            $resumenMsg = sprintf(
                "Propiedades - Creadas: %d | Actualizadas: %d | Sin cambios: %d | Omitidas: %d | Errores: %d | " .
                "Clientes - Creados: %d | Actualizados: %d | Sin cambios: %d | Omitidos: %d | Errores: %d | " .
                "Lados - Creados: %d | Actualizados: %d | Sin cambios: %d | Omitidos: %d | Errores: %d | " .
                "Tiempo total: %ss",
                $summary['propiedades']['created'],  $summary['propiedades']['updated'],
                $summary['propiedades']['no_changes'], $summary['propiedades']['skipped'],
                $summary['propiedades']['errors'],
                $summary['clientes']['created'],  $summary['clientes']['updated'],
                $summary['clientes']['no_changes'], $summary['clientes']['skipped'],
                $summary['clientes']['errors'],
                $summary['lados']['created'],  $summary['lados']['updated'],
                $summary['lados']['no_changes'], $summary['lados']['skipped'],
                $summary['lados']['errors'],
                $elapsed
            );

            $this->log('info', 'Resumen', null, 'Sincronización Propiedades+Clientes+Lados', $status,
                $resumenMsg, $config['id']);

        } catch (\Exception $e) {
            $errorMsg = 'Error crítico: ' . $e->getMessage();
            error_log('[SyncPropiedades] ' . $errorMsg);

            if ($config) {
                $this->log('error', 'Job', $config['id'], 'SincronizarPropiedades', 'error',
                    $errorMsg, $config['id']);
                $this->updateConfigStatus($config['id'], 'error');
            } else {
                $this->log('error', 'Job', null, 'SincronizarPropiedades', 'error',
                    $errorMsg, null);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function determineSyncType(): string
    {
        $dia  = (int)date('j');
        $hora = (int)date('G');

        if ($dia === 1 && $hora < 13) {
            return 'completa';
        }
        return 'anual';
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
                'id'                => $config->getId(),
                'name'              => $config->get('name'),
                'host'              => $this->decrypt($config->get('host')),
                'port'              => $config->get('port'),
                'database'          => $this->decrypt($config->get('database')),
                'username'          => $this->decrypt($config->get('username')),
                'password'          => $this->decrypt($config->get('password')),
                'notificationEmail' => $config->get('notificationEmail'),
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
            $config      = $this->injectableFactory->create('Espo\\Core\\Utils\\Config');
            $passwordSalt = $config->get('passwordSalt');
            $siteUrl     = $config->get('siteUrl');
            $secretKey   = hash('sha256', $passwordSalt . $siteUrl, true);

            $data = base64_decode($encryptedValue, true);
            if ($data === false) {
                return $encryptedValue;
            }

            $iv        = substr($data, 0, 16);
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

            return new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 10,
                ]
            );
        } catch (PDOException $e) {
            return null;
        }
    }

    private function updateConfigStatus(string $configId, string $status): void
    {
        try {
            $config = $this->entityManager->getEntityById('ExternalDbConfig', $configId);
            if ($config) {
                $config->set([
                    'lastSync'       => date('Y-m-d H:i:s'),
                    'lastSyncStatus' => $status,
                ]);
                $this->entityManager->saveEntity($config);
            }
        } catch (\Exception $e) {
        }
    }
}
