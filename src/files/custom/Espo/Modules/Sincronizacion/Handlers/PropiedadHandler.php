<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Traits\Loggable;

class PropiedadHandler
{
    use Loggable;

    private EntityManager $entityManager;
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    public function syncPropiedades(
        \PDO $pdo,
        string $syncType,
        string $configId,
        array &$summary
    ): void {
        $whereClause = '';
        if ($syncType === 'anual') {
            $whereClause = 'WHERE fechaModificacion >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
        }
        
        $sqlCount = "SELECT COUNT(*) as total FROM propiedades {$whereClause}";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute();
        $totalRegistros = $stmtCount->fetch(\PDO::FETCH_ASSOC)['total'];
        
        if ($totalRegistros == 0) {
            $this->log('info', 'Propiedades', null, 'Sincronización', 'success',
                      'No hay propiedades para sincronizar', $configId);
            return;
        }
        
        $pageSize = 1000;
        $totalPaginas = ceil($totalRegistros / $pageSize);
        
        $this->log('info', 'Propiedades', null, 'Sincronización', 'success',
                  "Procesando {$totalRegistros} propiedades en {$totalPaginas} páginas", $configId);
        
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
        
        $procesadas = 0;
        
        for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
            $offset = $pagina * $pageSize;
            
            $stmt->bindValue(1, $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            $propiedades = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (($pagina + 1) % 5 == 0 || $pagina == 0 || $pagina == $totalPaginas - 1) {
                $this->log('info', 'Propiedades', null, 'Progreso', 'success',
                          "Página " . ($pagina + 1) . "/{$totalPaginas} - " .
                          "Procesadas: {$procesadas}/{$totalRegistros}", $configId);
            }
            
            foreach ($propiedades as $propiedadExterna) {
                $procesadas++;
                
                try {
                    $this->syncPropiedad($propiedadExterna, $configId, $summary);
                } catch (\Exception $e) {
                    $summary['propiedades']['errors']++;
                    $idProp = $propiedadExterna['id'] ?? 'Unknown';
                    
                    $this->log('error', 'Propiedades', $idProp, "ID {$idProp}", 'error',
                              "Error: " . $e->getMessage(), $configId);
                }
            }
            
            if ($pagina < $totalPaginas - 1) {
                sleep(2);
            }
        }
        
        $this->log('info', 'Propiedades', null, 'Resumen Final', 'success',
                  "Creadas: {$summary['propiedades']['created']} | " .
                  "Actualizadas: {$summary['propiedades']['updated']} | " .
                  "Sin cambios: {$summary['propiedades']['no_changes']} | " .
                  "Omitidas: {$summary['propiedades']['skipped']} | " .
                  "Errores: {$summary['propiedades']['errors']}", $configId);
    }
    
    private function syncPropiedad(
        array $propiedadExterna,
        string $configId,
        array &$summary
    ): void {
        if (!$this->validatePropiedadData($propiedadExterna, $summary, $configId)) {
            return;
        }
        
        $propiedadId = (string)$propiedadExterna['id'];
        $propiedad = $this->entityManager->getEntityById('Propiedades', $propiedadId);
        
        if (!$propiedad) {
            $this->createPropiedad($propiedadExterna, $propiedadId, $configId, $summary);
        } else {
            $this->updatePropiedad($propiedad, $propiedadExterna, $configId, $summary);
        }
    }
    
    private function validatePropiedadData(array $propiedadExterna, array &$summary, string $configId): bool
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
                $summary['propiedades']['skipped']++;
                
                $this->log('info', 'Propiedades', $id, "ID {$id}", 'warning',
                          "Propiedad omitida: falta campo '{$nombre}'", $configId);
                return false;
            }
        }
        
        return true;
    }
    
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
            
            $this->log('created', 'Propiedades', $propiedadId, $propiedadData['name'], 'success',
                      "Propiedad creada", $configId);
            
        } catch (\Exception $e) {
            $summary['propiedades']['errors']++;
            $this->log('error', 'Propiedades', $propiedadId, "ID {$propiedadId}", 'error',
                      "Error al crear: " . $e->getMessage(), $configId);
            throw $e;
        }
    }
    
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
        
        foreach ($propiedadData as $field => $newValue) {
            $currentValue = $propiedad->get($field);
            $normalizedCurrent = StringUtils::normalize($currentValue);
            $normalizedNew = StringUtils::normalize($newValue);
            
            if ($normalizedCurrent !== $normalizedNew) {
                $propiedad->set($field, $newValue);
                $needsUpdate = true;
                $changes[] = $field;
            }
        }
        
        if ($needsUpdate) {
            try {
                $this->entityManager->saveEntity($propiedad);
                
                $summary['propiedades']['updated']++;
                $changesStr = implode(', ', $changes);
                
                $this->log('updated', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'success',
                          "Propiedad actualizada: {$changesStr}", $configId);
                
            } catch (\Exception $e) {
                $summary['propiedades']['errors']++;
                $this->log('error', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'error',
                          "Error al actualizar: " . $e->getMessage(), $configId);
                throw $e;
            }
        } else {
            $summary['propiedades']['no_changes']++;
        }
    }
    
    private function preparePropiedadData(array $propiedadExterna): ?array
    {
        $tipoOperacion = $propiedadExterna['tipoOperacion'] ?? 'N/A';
        $tipoPropiedad = $propiedadExterna['tipoPropiedad'] ?? 'N/A';
        $urbanizacion = $propiedadExterna['colonia2'] ?? $propiedadExterna['colonia'] ?? 'Sin especificar';
        $name = "{$tipoOperacion} - {$tipoPropiedad} - {$urbanizacion}";
        
        $fechaAlta = !empty($propiedadExterna['fechaAlta']) 
            ? $propiedadExterna['fechaAlta']
            : date('Y-m-d H:i:s');
        
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
        
        $camposOpcionales = [
            'fechaModificacion' => 'fechaModificacion',
            'comision' => 'comision',
            'precioEnContrato' => 'precioEnContrato',
            'monedaEnContrato' => 'monedaEnContrato',
            'calle' => 'calle',
            'numero' => 'numero',
            'municipio' => 'colonia',
            'urbanizacion' => 'colonia2',
            'ciudad' => 'municipio',
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
}