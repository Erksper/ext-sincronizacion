<?php

namespace Espo\Modules\Sincronizacion\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;

/**
 * Job programado para sincronizar propiedades desde 21online
 * 
 * - Sincronización incremental (últimos 12 meses) por defecto
 * - Sincronización completa el día 1 del mes antes de 13:00
 * - Procesamiento paginado de 1000 registros por página
 */
class SincronizarPropiedades implements JobDataLess
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
            // Configuración de límites
            set_time_limit(0);
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', 0);
            
            error_log('[SyncPropiedades] ========== INICIANDO SINCRONIZACIÓN DE PROPIEDADES ==========');
            
            // 1. Obtener configuración activa
            $config = $this->getActiveConfig();
            if (!$config) {
                error_log('[SyncPropiedades] No hay configuración activa de BD externa');
                return;
            }
            
            error_log("[SyncPropiedades] Usando configuración: {$config['name']}");
            
            // 2. Conectar a la base de datos externa
            $pdo = $this->connectToExternalDb($config);
            if (!$pdo) {
                $this->updateConfigStatus($config['id'], 'error');
                error_log('[SyncPropiedades] No se pudo conectar a la BD externa');
                return;
            }
            
            // 3. Determinar tipo de sincronización
            $syncType = $this->determineSyncType();
            
            // 4. Inicializar resumen
            $summary = [
                'propiedades' => [
                    'created' => 0,
                    'updated' => 0,
                    'no_changes' => 0,
                    'skipped' => 0,
                    'errors' => 0
                ]
            ];
            
            // 5. Sincronizar propiedades
            $propiedadHandler = $this->injectableFactory->create('Espo\\Modules\\Sincronizacion\\Handlers\\PropiedadHandler');
            $propiedadHandler->syncPropiedades($pdo, $syncType, $config['id'], $summary);
            
            // 6. Cerrar conexión
            $pdo = null;
            
            // 7. Actualizar estado
            $this->updateConfigStatus($config['id'], 'success');
            
            // 8. Guardar incidencias
            $this->saveIncidencias($propiedadHandler->getIncidencias(), $config['id']);
            
            error_log('[SyncPropiedades] ========== SINCRONIZACIÓN COMPLETADA ==========');
            
        } catch (\Exception $e) {
            error_log('[SyncPropiedades] ERROR CRÍTICO: ' . $e->getMessage());
            error_log('[SyncPropiedades] Stack trace: ' . $e->getTraceAsString());
            
            if (isset($config)) {
                $this->updateConfigStatus($config['id'], 'error');
            }
        }
    }
    
    /**
     * Determinar tipo de sincronización
     * 
     * @return string 'completa' o 'anual'
     */
    private function determineSyncType(): string
    {
        // Verificar si se fuerza un tipo específico (desde panel manual)
        $forcedType = getenv('FORCE_SYNC_TYPE');
        if ($forcedType === 'completa' || $forcedType === 'anual') {
            error_log("[SyncPropiedades] Tipo FORZADO desde panel: {$forcedType}");
            return $forcedType;
        }
        
        $dia = (int)date('j');
        $hora = (int)date('G');
        
        // Día 1 del mes y hora < 13:00 → sincronización completa
        if ($dia === 1 && $hora < 13) {
            error_log('[SyncPropiedades] Sincronización COMPLETA (día 1 del mes antes de 13:00)');
            return 'completa';
        }
        
        // Otros días → sincronización anual (últimos 12 meses)
        error_log('[SyncPropiedades] Sincronización ANUAL (últimos 12 meses)');
        return 'anual';
    }
    
    /**
     * Obtener configuración activa
     */
    private function getActiveConfig(): ?array
    {
        try {
            $config = $this->entityManager->getRDBRepository('SyncConfig')
                ->where(['isActive' => true])
                ->findOne();
            
            if (!$config) {
                return null;
            }
            
            return [
                'id' => $config->getId(),
                'name' => $config->get('name'),
                'host' => $config->get('dbHost'),
                'port' => $config->get('dbPort'),
                'database' => $config->get('dbName'),
                'username' => $config->get('dbUser'),
                'password' => $config->get('dbPassword')
            ];
        } catch (\Exception $e) {
            error_log('[SyncPropiedades] Error obteniendo configuración: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Conectar a la base de datos externa
     */
    private function connectToExternalDb(array $config): ?PDO
    {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
            
            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            error_log('[SyncPropiedades] Conexión a BD externa establecida exitosamente');
            return $pdo;
            
        } catch (PDOException $e) {
            error_log('[SyncPropiedades] Error conectando a BD externa: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Actualizar estado de la configuración
     */
    private function updateConfigStatus(string $configId, string $status): void
    {
        try {
            $config = $this->entityManager->getEntityById('SyncConfig', $configId);
            if ($config) {
                $config->set('lastSyncDate', date('Y-m-d H:i:s'));
                $config->set('lastSyncStatus', $status);
                $this->entityManager->saveEntity($config);
            }
        } catch (\Exception $e) {
            error_log('[SyncPropiedades] Error actualizando estado: ' . $e->getMessage());
        }
    }
    
    /**
     * Guardar incidencias
     */
    private function saveIncidencias(array $incidencias, string $configId): void
    {
        error_log('[SyncPropiedades] Guardando ' . count($incidencias) . ' incidencias...');
        
        foreach ($incidencias as $incidencia) {
            try {
                $entity = $this->entityManager->getNewEntity('SyncIncidencia');
                $entity->set([
                    'tipo' => $incidencia['tipo'],
                    'entityType' => $incidencia['entityType'],
                    'entityId' => $incidencia['entityId'],
                    'entityName' => $incidencia['entityName'],
                    'mensaje' => $incidencia['mensaje'],
                    'configId' => $configId
                ]);
                
                $this->entityManager->saveEntity($entity);
            } catch (\Exception $e) {
                error_log('[SyncPropiedades] Error guardando incidencia: ' . $e->getMessage());
            }
        }
    }
}
