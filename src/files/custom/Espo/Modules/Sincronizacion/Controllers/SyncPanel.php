<?php

namespace Espo\Modules\Sincronizacion\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Api\Request;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;

class SyncPanel extends Base
{
    private RecordServiceContainer $recordServiceContainer;
    private InjectableFactory $injectableFactory;
    private Log $log;
    
    public function __construct(
        RecordServiceContainer $recordServiceContainer,
        InjectableFactory $injectableFactory,
        Log $log
    ) {
        $this->recordServiceContainer = $recordServiceContainer;
        $this->injectableFactory = $injectableFactory;
        $this->log = $log;
    }
    
    /**
     * GET SyncPanel/action/testConnection
     * El ACL en metadata/aclDefs ya valida que solo admins pueden acceder
     */
    public function getActionTestConnection(Request $request): array
    {
        try {
            $service = $this->recordServiceContainer->get('ExternalDbConfig');
            $config = $service->getActiveConfigDecrypted();
            
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'No hay configuración activa. Por favor, crea y activa una configuración primero.'
                ];
            }
            
            try {
                $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
                
                $pdo = new \PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_TIMEOUT => 5
                    ]
                );
                
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE isActive = 1");
                $users = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM afiliados WHERE isActive = 1");
                $teams = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                $pdo = null;
                
                return [
                    'success' => true,
                    'message' => 'Conexión exitosa a la base de datos externa',
                    'data' => [
                        'config' => $config['name'],
                        'userCount' => $users['total'] ?? 0,
                        'teamCount' => $teams['total'] ?? 0
                    ]
                ];
                
            } catch (\PDOException $e) {
                return [
                    'success' => false,
                    'message' => 'Error de conexión a BD: ' . $e->getMessage()
                ];
            }
            
        } catch (\Exception $e) {
            $this->log->error('Error en SyncPanel::testConnection: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * POST SyncPanel/action/runSync
     * El ACL en metadata/aclDefs ya valida que solo admins pueden acceder
     */
    public function postActionRunSync(Request $request): array
    {
        try {
            $service = $this->recordServiceContainer->get('ExternalDbConfig');
            $config = $service->getActiveConfigDecrypted();
            
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'No hay configuración activa. Por favor, crea y activa una configuración primero.'
                ];
            }
            
            $job = $this->injectableFactory->create(
                'Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarDatosExternos'
            );
            
            ob_start();
            $job->run();
            ob_end_clean();
            
            return [
                'success' => true,
                'message' => 'Sincronización ejecutada correctamente. Revisa los logs de sincronización para ver los detalles.'
            ];
            
        } catch (\Exception $e) {
            $this->log->error('Error en SyncPanel::runSync: ' . $e->getMessage());
            $this->log->error('Trace: ' . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'Error al ejecutar sincronización: ' . $e->getMessage()
            ];
        }
    }
}