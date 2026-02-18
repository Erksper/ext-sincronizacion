<?php

namespace Espo\Modules\Sincronizacion\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;

/**
 * Controlador API para el panel de sincronización
 */
class SyncPanel
{
    private InjectableFactory $injectableFactory;
    private EntityManager $entityManager;
    
    public function __construct(
        InjectableFactory $injectableFactory,
        EntityManager $entityManager
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
    }
    
    /**
     * POST /api/v1/SyncPanel/action/runSync
     * Ejecutar sincronización manual de usuarios y teams
     */
    public function postActionRunSync(Request $request): bool
    {
        try {
            error_log('[SyncPanel] Ejecutando sincronización manual de usuarios...');
            
            $job = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarUsuarios');
            $job->run();
            
            error_log('[SyncPanel] Sincronización completada exitosamente');
            return true;
            
        } catch (\Exception $e) {
            error_log('[SyncPanel] Error en sincronización: ' . $e->getMessage());
            throw new BadRequest('Error en sincronización: ' . $e->getMessage());
        }
    }
    
    /**
     * POST /api/v1/SyncPanel/action/runSyncPropiedadesAnual
     * Ejecutar sincronización anual de propiedades (últimos 12 meses)
     */
    public function postActionRunSyncPropiedadesAnual(Request $request): bool
    {
        try {
            error_log('[SyncPanel] Ejecutando sincronización ANUAL de propiedades (últimos 12 meses)...');
            
            // Crear instancia del job
            $job = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarPropiedades');
            
            // Forzar tipo anual mediante variable de entorno temporal
            putenv('FORCE_SYNC_TYPE=anual');
            
            $job->run();
            
            putenv('FORCE_SYNC_TYPE=');
            
            error_log('[SyncPanel] Sincronización anual de propiedades completada');
            return true;
            
        } catch (\Exception $e) {
            error_log('[SyncPanel] Error en sincronización anual: ' . $e->getMessage());
            throw new BadRequest('Error en sincronización anual: ' . $e->getMessage());
        }
    }
    
    /**
     * POST /api/v1/SyncPanel/action/runSyncPropiedadesCompleta
     * Ejecutar sincronización completa de propiedades (todos los registros)
     */
    public function postActionRunSyncPropiedadesCompleta(Request $request): bool
    {
        try {
            error_log('[SyncPanel] Ejecutando sincronización COMPLETA de propiedades...');
            
            // Crear instancia del job
            $job = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Jobs\\SincronizarPropiedades');
            
            // Forzar tipo completa mediante variable de entorno temporal
            putenv('FORCE_SYNC_TYPE=completa');
            
            $job->run();
            
            putenv('FORCE_SYNC_TYPE=');
            
            error_log('[SyncPanel] Sincronización completa de propiedades finalizada');
            return true;
            
        } catch (\Exception $e) {
            error_log('[SyncPanel] Error en sincronización completa: ' . $e->getMessage());
            throw new BadRequest('Error en sincronización completa: ' . $e->getMessage());
        }
    }
    
    /**
     * GET /api/v1/SyncPanel/action/getStatus
     * Obtener estado actual de las sincronizaciones
     */
    public function getActionGetStatus(Request $request): array
    {
        try {
            $config = $this->entityManager->getRDBRepository('SyncConfig')
                ->where(['isActive' => true])
                ->findOne();
            
            if (!$config) {
                return [
                    'hasConfig' => false,
                    'message' => 'No hay configuración activa'
                ];
            }
            
            // Obtener últimos logs
            $logs = $this->entityManager->getRDBRepository('SyncLog')
                ->where(['configId' => $config->getId()])
                ->order('syncDate', 'DESC')
                ->limit(0, 10)
                ->find();
            
            $logsArray = [];
            foreach ($logs as $log) {
                $logsArray[] = [
                    'date' => $log->get('syncDate'),
                    'entity' => $log->get('entityType'),
                    'action' => $log->get('action'),
                    'status' => $log->get('status'),
                    'message' => $log->get('message')
                ];
            }
            
            return [
                'hasConfig' => true,
                'configName' => $config->get('name'),
                'lastSyncDate' => $config->get('lastSyncDate'),
                'lastSyncStatus' => $config->get('lastSyncStatus'),
                'recentLogs' => $logsArray
            ];
            
        } catch (\Exception $e) {
            error_log('[SyncPanel] Error obteniendo estado: ' . $e->getMessage());
            return [
                'hasConfig' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}