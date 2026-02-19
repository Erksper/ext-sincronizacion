<?php
namespace Espo\Modules\Sincronizacion\Traits;

trait Loggable
{
    protected function log(
        string $action,
        string $entityType,
        ?string $entityId,
        string $entityName,
        string $status,
        string $message,
        ?string $configId = null
    ): void {
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
            error_log("[Loggable] Error guardando log: " . $e->getMessage());
        }
    }
}