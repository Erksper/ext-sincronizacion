<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;

/**
 * Manejador de sincronización de propiedades
 * 
 * Sincroniza propiedades desde 21online a EspoCRM
 * - Sincronización incremental (últimos 12 meses) por defecto
 * - Sincronización completa el día 1 del mes antes de 13:00 o forzada
 * - Procesamiento en lotes de 1000 registros con pausas
 */
class PropiedadHandler
{
    private EntityManager $entityManager;
    private array $incidencias = [];
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    /**
     * Logging dual: error_log + EspoCRM Log global
     */
    private function logInfo(string $message): void
    {
        error_log($message);
        if (isset($GLOBALS['log'])) {
            $GLOBALS['log']->info($message);
        }
    }
    
    private function logError(string $message): void
    {
        error_log($message);
        if (isset($GLOBALS['log'])) {
            $GLOBALS['log']->error($message);
        }
    }
    
    /**
     * Sincronizar propiedades con paginación
     * 
     * @param \PDO $pdo Conexión a BD externa
     * @param string $syncType 'anual' o 'completa'
     * @param string $configId ID de configuración
     * @param array $summary Resumen de sincronización
     */
    public function syncPropiedades(
        \PDO $pdo,
        string $syncType,
        string $configId,
        array &$summary
    ): void {
        $this->logInfo('[PropiedadHandler] === Sincronizando Propiedades ===');
        $this->logInfo("[PropiedadHandler] Tipo de sincronización: {$syncType}");
        
        // Determinar query según tipo
        $whereClause = '';
        if ($syncType === 'anual') {
            $whereClause = 'WHERE fechaModificacion >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
        }
        
        // Obtener total de registros
        $sqlCount = "SELECT COUNT(*) as total FROM propiedades {$whereClause}";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute();
        $totalRegistros = $stmtCount->fetch(\PDO::FETCH_ASSOC)['total'];
        
        $this->logInfo("[PropiedadHandler] Total de registros a sincronizar: {$totalRegistros}");
        
        if ($totalRegistros == 0) {
            $this->logInfo('[PropiedadHandler] No hay registros para sincronizar');
            return;
        }
        
        // Configuración de paginación
        $pageSize = 1000;
        $totalPaginas = ceil($totalRegistros / $pageSize);
        
        $this->logInfo("[PropiedadHandler] Procesando en {$totalPaginas} páginas de {$pageSize} registros");
        
        // Query base
        $sqlBase = "SELECT 
            id, idAfiliados, fechaAlta, fechaModificacion,
            tipoOperacion, tipoPropiedad, subtipoPropiedad,
            tipoDeContrato, status, idAsesorExclusiva,
            comision, precioEnContrato, monedaEnContrato,
            calle, numero, colonia, colonia2, municipio, estado, pais,
            precioVenta, precioRenta, infoExtraPrecio, moneda,
            enInternet, m2T, m2C, edad
        FROM propiedades
        {$whereClause}
        ORDER BY id
        LIMIT ? OFFSET ?";
        
        $stmt = $pdo->prepare($sqlBase);
        
        // Procesar cada página
        $procesadas = 0;
        
        for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
            $offset = $pagina * $pageSize;
            
            $this->logInfo("[PropiedadHandler] === Procesando página " . ($pagina + 1) . "/{$totalPaginas} (offset: {$offset}) ===");
            
            $stmt->bindValue(1, $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            $propiedades = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $this->logInfo("[PropiedadHandler] Registros obtenidos en esta página: " . count($propiedades));
            
            // Procesar cada propiedad
            foreach ($propiedades as $propiedadExterna) {
                $procesadas++;
                
                try {
                    $this->syncPropiedad($propiedadExterna, $configId, $summary);
                    
                    if ($procesadas % 100 == 0) {
                        $this->logInfo("[PropiedadHandler] Progreso: {$procesadas}/{$totalRegistros} propiedades procesadas");
                    }
                    
                } catch (\Exception $e) {
                    $summary['propiedades']['errors']++;
                    $idProp = $propiedadExterna['id'] ?? 'Unknown';
                    $mensaje = "Error sincronizando propiedad ID {$idProp}: " . $e->getMessage();
                    error_log("[PropiedadHandler] ERROR: {$mensaje}");
                    error_log("[PropiedadHandler] Stack trace: " . $e->getTraceAsString());
                    $this->addIncidencia('sync_error', 'Propiedades', $idProp, $idProp, $mensaje);
                    $this->addLog('error', 'Propiedades', $idProp, "Propiedad {$idProp}", 'error', $mensaje, $configId);
                }
            }
            
            // Pausa entre páginas
            if ($pagina < $totalPaginas - 1) {
                $this->logInfo("[PropiedadHandler] Página " . ($pagina + 1) . " completada. Pausa de 2 segundos...");
                sleep(2);
            }
        }
        
        $this->logInfo('[PropiedadHandler] ========================================');
        $this->logInfo('[PropiedadHandler] RESUMEN DE SINCRONIZACIÓN DE PROPIEDADES:');
        $this->logInfo('[PropiedadHandler] - Total registros: ' . $totalRegistros);
        $this->logInfo('[PropiedadHandler] - Procesadas: ' . $procesadas);
        $this->logInfo('[PropiedadHandler] - Creadas: ' . ($summary['propiedades']['created'] ?? 0));
        $this->logInfo('[PropiedadHandler] - Actualizadas: ' . ($summary['propiedades']['updated'] ?? 0));
        $this->logInfo('[PropiedadHandler] - Sin cambios: ' . ($summary['propiedades']['no_changes'] ?? 0));
        $this->logInfo('[PropiedadHandler] - Omitidas: ' . ($summary['propiedades']['skipped'] ?? 0));
        $this->logInfo('[PropiedadHandler] - Errores: ' . ($summary['propiedades']['errors'] ?? 0));
        $this->logInfo('[PropiedadHandler] ========================================');
    }
    
    /**
     * Sincronizar una propiedad individual
     */
    private function syncPropiedad(
        array $propiedadExterna,
        string $configId,
        array &$summary
    ): void {
        // Validar campos obligatorios
        if (!$this->validatePropiedadData($propiedadExterna, $summary)) {
            return;
        }
        
        $propiedadId = (string)$propiedadExterna['id'];
        
        // Buscar propiedad existente por ID
        $propiedad = $this->entityManager->getEntityById('Propiedades', $propiedadId);
        
        if (!$propiedad) {
            // Crear nueva propiedad
            $this->createPropiedad($propiedadExterna, $propiedadId, $configId, $summary);
        } else {
            // Actualizar propiedad existente
            $this->updatePropiedad($propiedad, $propiedadExterna, $configId, $summary);
        }
    }
    
    /**
     * Validar datos obligatorios de la propiedad
     */
    private function validatePropiedadData(array $propiedadExterna, array &$summary): bool
    {
        $camposObligatorios = [
            'id' => 'ID',
            'idAfiliados' => 'Oficina (idAfiliados)',
            'fechaAlta' => 'Fecha de Alta',
            'tipoOperacion' => 'Tipo de Operación',
            'tipoPropiedad' => 'Tipo de Propiedad',
            'subtipoPropiedad' => 'Subtipo de Propiedad',
            'tipoDeContrato' => 'Tipo de Contrato',
            'status' => 'Estado',
            'idAsesorExclusiva' => 'Asesor Exclusiva'
        ];
        
        foreach ($camposObligatorios as $campo => $nombre) {
            if (empty($propiedadExterna[$campo])) {
                $id = $propiedadExterna['id'] ?? 'Unknown';
                $this->addIncidencia('validation_error', 'Propiedades', $id, $id,
                                   "Propiedad ID {$id} sin campo obligatorio: {$nombre}");
                $summary['propiedades']['skipped']++;
                error_log("[PropiedadHandler] ✗ Propiedad ID {$id} RECHAZADA: falta campo '{$nombre}'");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Crear nueva propiedad
     */
    private function createPropiedad(
        array $propiedadExterna,
        string $propiedadId,
        string $configId,
        array &$summary
    ): void {
        $propiedadData = $this->preparePropiedadData($propiedadExterna);
        
        if (!$propiedadData) {
            $summary['propiedades']['skipped']++;
            return;
        }
        
        try {
            $propiedad = $this->entityManager->getNewEntity('Propiedades');
            $propiedad->set('id', $propiedadId);
            $propiedad->set($propiedadData);
            
            $this->entityManager->saveEntity($propiedad);
            
            $summary['propiedades']['created']++;
            $this->addLog('created', 'Propiedades', $propiedadId, $propiedadData['name'], 'success',
                         "Propiedad creada", $configId);
            
            error_log("[PropiedadHandler] ✓ Propiedad creada: ID {$propiedadId}");
            
        } catch (\Exception $e) {
            $this->logError("[PropiedadHandler] ERROR al crear propiedad ID {$propiedadId}: " . $e->getMessage());
            $summary['propiedades']['errors']++;
            throw $e;
        }
    }
    
    /**
     * Actualizar propiedad existente
     */
    private function updatePropiedad(
        $propiedad,
        array $propiedadExterna,
        string $configId,
        array &$summary
    ): void {
        $propiedadData = $this->preparePropiedadData($propiedadExterna);
        
        if (!$propiedadData) {
            return;
        }
        
        $changes = [];
        $needsUpdate = false;
        
        // Comparar cada campo
        foreach ($propiedadData as $field => $newValue) {
            $currentValue = $propiedad->get($field);
            
            // Normalizar para comparación
            $normalizedCurrent = StringUtils::normalize($currentValue);
            $normalizedNew = StringUtils::normalize($newValue);
            
            if ($normalizedCurrent !== $normalizedNew) {
                $propiedad->set($field, $newValue);
                $needsUpdate = true;
                $changes[] = $field;
            }
        }
        
        // Guardar si hay cambios
        if ($needsUpdate) {
            try {
                $this->entityManager->saveEntity($propiedad);
                
                $summary['propiedades']['updated']++;
                $changesStr = implode(', ', $changes);
                $this->addLog('updated', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'success',
                             "Propiedad actualizada: {$changesStr}", $configId);
                
            } catch (\Exception $e) {
                $this->logError("[PropiedadHandler] ERROR al actualizar propiedad: " . $e->getMessage());
                $summary['propiedades']['errors']++;
                throw $e;
            }
        } else {
            $summary['propiedades']['no_changes']++;
        }
    }
    
    /**
     * Preparar datos de propiedad formateados
     */
    private function preparePropiedadData(array $propiedadExterna): ?array
    {
        // Generar name usando Opción C: "{tipoOperacion} - {tipoPropiedad} - {urbanizacion}"
        $tipoOperacion = $propiedadExterna['tipoOperacion'] ?? 'N/A';
        $tipoPropiedad = $propiedadExterna['tipoPropiedad'] ?? 'N/A';
        $urbanizacion = $propiedadExterna['colonia2'] ?? $propiedadExterna['colonia'] ?? 'Sin especificar';
        
        $name = "{$tipoOperacion} - {$tipoPropiedad} - {$urbanizacion}";
        
        // Fecha de alta: usar de BD o generar
        $fechaAlta = !empty($propiedadExterna['fechaAlta']) 
            ? $propiedadExterna['fechaAlta']
            : date('Y-m-d H:i:s');
        
        // Datos base (siempre presentes)
        $data = [
            'name' => $name,
            'idOficinaId' => (string)$propiedadExterna['idAfiliados'],
            'fechaAlta' => $fechaAlta,
            'tipoOperacion' => $propiedadExterna['tipoOperacion'],
            'tipoPropiedad' => $propiedadExterna['tipoPropiedad'],
            'subTipoPropiedad' => $propiedadExterna['subtipoPropiedad'],
            'tipoDeContrato' => $propiedadExterna['tipoDeContrato'],
            'status' => $propiedadExterna['status'],
            'idAsesorExclusivaId' => (string)$propiedadExterna['idAsesorExclusiva'],
            'assignedUserId' => (string)$propiedadExterna['idAsesorExclusiva']
        ];
        
        // Campos opcionales
        $camposOpcionales = [
            'fechaModificacion' => 'fechaModificacion',
            'comision' => 'comision',
            'precioEnContrato' => 'precioEnContrato',
            'monedaEnContrato' => 'monedaEnContrato',
            'calle' => 'calle',
            'numero' => 'numero',
            'municipio' => 'colonia',      // Mapeo: municipio ← colonia
            'urbanizacion' => 'colonia2',  // Mapeo: urbanizacion ← colonia2
            'ciudad' => 'municipio',       // Mapeo: ciudad ← municipio
            'estado' => 'estado',
            'pais' => 'pais',
            'precioVenta' => 'precioVenta',
            'precioRenta' => 'precioRenta',
            'infoExtraPrecio' => 'infoExtraPrecio',
            'moneda' => 'moneda',
            'enInternet' => 'enInternet',
            'm2T' => 'm2T',
            'm2C' => 'm2C',
            'edad' => 'edad'
        ];
        
        foreach ($camposOpcionales as $campoEspo => $campo21online) {
            if (!empty($propiedadExterna[$campo21online])) {
                $data[$campoEspo] = $propiedadExterna[$campo21online];
            }
        }
        
        return $data;
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
     * Obtener incidencias acumuladas
     */
    public function getIncidencias(): array
    {
        return $this->incidencias;
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
            error_log('[PropiedadHandler] Error creando log: ' . $e->getMessage());
        }
    }
}
