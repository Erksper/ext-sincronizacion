<?php

namespace Espo\Modules\Sincronizacion\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;

class SyncPanel extends Base
{
    /**
     * GET SyncPanel/action/testConnection
     */
    public function getActionTestConnection(Request $request): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores');
        }
        
        try {
            $service = $this->recordServiceContainer->get('ExternalDbConfig');
            $config = $service->getActiveConfigDecrypted();
            
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'No hay configuración activa'
                ];
            }
            
            $pdo = new \PDO(
                "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
                $config['username'],
                $config['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE isActive = 1");
            $users = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM afiliados WHERE isActive = 1");
            $teams = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'data' => [
                    'config' => $config['name'],
                    'userCount' => $users['total'] ?? 0,
                    'teamCount' => $teams['total'] ?? 0
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * POST SyncPanel/action/runSync
     */
    public function postActionRunSync(Request $request): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores');
        }
        
        try {
            $job = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarDatosExternos');
            
            ob_start();
            $job->run();
            ob_end_clean();
            
            return [
                'success' => true,
                'message' => 'Sincronización completada. Revisa los logs para más detalles.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
}