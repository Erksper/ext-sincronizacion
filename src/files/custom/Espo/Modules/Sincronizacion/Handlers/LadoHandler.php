<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Traits\Loggable;

class LadoHandler
{
    use Loggable;

    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function syncLados(
        \PDO   $pdo,
        string $configId,
        array  &$summary
    ): void {
        $startTime = microtime(true);

        $sql = "SELECT
                    id,
                    idPropiedades,
                    idAsesor,
                    idAfiliados,
                    tipoLado,
                    porcentaje,
                    asesorPrincipal
                FROM asignacion
                ORDER BY id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $lados = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = count($lados);

        if ($total === 0) {
            $this->log('info', 'Lados', null, 'Sincronización', 'success',
                'No hay lados para sincronizar', $configId);
            return;
        }

        $this->log('info', 'Lados', null, 'Sincronización', 'success',
            "Procesando {$total} lados", $configId);

        $summary['lados'] = [
            'created'    => 0,
            'updated'    => 0,
            'no_changes' => 0,
            'skipped'    => 0,
            'errors'     => 0,
        ];

        foreach ($lados as $ladoExterno) {
            try {
                $this->syncLado($ladoExterno, $configId, $summary);
            } catch (\Exception $e) {
                $summary['lados']['errors']++;
                $id = $ladoExterno['id'] ?? 'Unknown';
                $this->log('error', 'Lados', (string)$id, "ID {$id}", 'error',
                    'Error: ' . $e->getMessage(), $configId);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        $this->log('info', 'Lados', null, 'Resumen Lados', 'success',
            "Creados: {$summary['lados']['created']} | " .
            "Actualizados: {$summary['lados']['updated']} | " .
            "Sin cambios: {$summary['lados']['no_changes']} | " .
            "Omitidos: {$summary['lados']['skipped']} | " .
            "Errores: {$summary['lados']['errors']} | " .
            "Tiempo: {$elapsed}s", $configId);
    }

    // -------------------------------------------------------------------------
    // Lógica interna
    // -------------------------------------------------------------------------

    private function syncLado(array $ext, string $configId, array &$summary): void
    {
        if (empty($ext['id'])) {
            $summary['lados']['skipped']++;
            $this->log('info', 'Lados', null, 'Unknown', 'warning',
                'Lado omitido: sin ID', $configId);
            return;
        }

        if (empty($ext['idPropiedades'])) {
            $summary['lados']['skipped']++;
            $id = $ext['id'];
            $this->log('info', 'Lados', (string)$id, "ID {$id}", 'warning',
                'Lado omitido: sin idPropiedades', $configId);
            return;
        }

        $ladoId = (string)$ext['id'];
        $lado   = $this->entityManager->getEntityById('Lados', $ladoId);

        if (!$lado) {
            $this->createLado($ext, $ladoId, $configId, $summary);
        } else {
            $this->updateLado($lado, $ext, $configId, $summary);
        }
    }

    private function createLado(
        array  $ext,
        string $ladoId,
        string $configId,
        array  &$summary
    ): void {
        $data = $this->prepareLadoData($ext);

        try {
            $lado = $this->entityManager->getNewEntity('Lados');
            $lado->set('id', $ladoId);
            $lado->set($data);

            $this->entityManager->saveEntity($lado);

            $summary['lados']['created']++;
            $this->log('created', 'Lados', $ladoId, $data['name'], 'success',
                'Lado creado', $configId);

        } catch (\Exception $e) {
            $summary['lados']['errors']++;
            $this->log('error', 'Lados', $ladoId, "ID {$ladoId}", 'error',
                'Error al crear: ' . $e->getMessage(), $configId);
            throw $e;
        }
    }

    private function updateLado(
        $lado,
        array  $ext,
        string $configId,
        array  &$summary
    ): void {
        $data    = $this->prepareLadoData($ext);
        $changes = [];

        foreach ($data as $field => $newValue) {
            $currentValue = $lado->get($field);

            // Normalización según tipo
            if (in_array($field, ['asesorPrincipal'])) {
                // bool
                $currentNorm = $currentValue ? '1' : '0';
                $newNorm     = $newValue ? '1' : '0';
            } elseif ($field === 'porcentaje') {
                // float con 2 decimales
                $currentNorm = number_format((float)$currentValue, 2, '.', '');
                $newNorm     = number_format((float)$newValue, 2, '.', '');
            } else {
                $currentNorm = trim((string)($currentValue ?? ''));
                $newNorm     = trim((string)($newValue ?? ''));
            }

            if ($currentNorm !== $newNorm) {
                $lado->set($field, $newValue);
                $changes[] = $field;
            }
        }

        if (!empty($changes)) {
            try {
                $this->entityManager->saveEntity($lado);

                $summary['lados']['updated']++;
                $this->log('updated', 'Lados', $lado->getId(), $data['name'], 'success',
                    'Lado actualizado: ' . implode(', ', $changes), $configId);

            } catch (\Exception $e) {
                $summary['lados']['errors']++;
                $this->log('error', 'Lados', $lado->getId(), "ID {$lado->getId()}", 'error',
                    'Error al actualizar: ' . $e->getMessage(), $configId);
                throw $e;
            }
        } else {
            $summary['lados']['no_changes']++;
        }
    }

    private function prepareLadoData(array $ext): array
    {
        $propiedadId = (string)$ext['idPropiedades'];
        $tipoLado    = mb_strtolower(trim($ext['tipoLado'] ?? ''), 'UTF-8');

        // Validar que tipoLado sea un valor conocido
        if (!in_array($tipoLado, ['cierre', 'obtencion'])) {
            $tipoLado = '';
        }

        $name = "Lado {$tipoLado} - Propiedad {$propiedadId}";

        $data = [
            'name'             => $name,
            'propiedadId'      => $propiedadId,
            'tipoLado'         => $tipoLado,
            'porcentaje'       => isset($ext['porcentaje']) ? (float)$ext['porcentaje'] : 0.0,
            'asesorPrincipal'  => !empty($ext['asesorPrincipal']),
        ];

        // Links opcionales
        if (!empty($ext['idAsesor'])) {
            $data['asesorId'] = (string)$ext['idAsesor'];
        }
        if (!empty($ext['idAfiliados'])) {
            $data['oficinaId'] = (string)$ext['idAfiliados'];
        }

        return $data;
    }
}
