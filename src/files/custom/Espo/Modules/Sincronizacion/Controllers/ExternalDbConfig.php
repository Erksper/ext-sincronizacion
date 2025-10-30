<?php

namespace Espo\Modules\Sincronizacion\Controllers;

use Espo\Core\Controllers\Record;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;

class ExternalDbConfig extends Record
{
    /**
     * POST ExternalDbConfig/action/runSync
     * Ejecuta la sincronización manualmente
     */
    public function postActionRunSync(Request $request): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores pueden ejecutar sincronizaciones');
        }
        
        $id = $request->getParsedBody()->id ?? null;
        
        if (!$id) {
            throw new BadRequest('ID de configuración requerido');
        }
        
        try {
            // Crear instancia del Job y ejecutarlo
            $job = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarDatosExternos');
            
            // Ejecutar en el mismo proceso (sin cron)
            ob_start();
            $job->run();
            $output = ob_get_clean();
            
            return [
                'success' => true,
                'message' => 'Sincronización ejecutada. Revisa los logs para ver detalles.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * POST ExternalDbConfig/action/testConnection
     * Prueba la conexión a la BD externa
     */
    public function postActionTestConnection(Request $request): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores pueden probar conexiones');
        }
        
        $id = $request->getParsedBody()->id ?? null;
        
        if (!$id) {
            throw new BadRequest('ID de configuración requerido');
        }
        
        try {
            $service = $this->recordServiceContainer->get('ExternalDbConfig');
            $config = $service->getActiveConfigDecrypted();
            
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'Configuración no encontrada o inactiva'
                ];
            }
            
            // Intentar conectar
            $pdo = new \PDO(
                "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
                $config['username'],
                $config['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5
                ]
            );
            
            // Contar usuarios
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE isActive = 1");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'userCount' => $result['total'] ?? 0
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
}