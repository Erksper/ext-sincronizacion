<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Traits\Loggable;

class ClienteHandler
{
    use Loggable;

    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function syncClientes(
        \PDO $pdo,
        string $configId,
        array &$summary
    ): void {
        $startTime = microtime(true);

        $sql = "SELECT
                    id,
                    idAfiliados,
                    idAsesor,
                    nombre,
                    apellidoM,
                    apellidoP,
                    titulo,
                    tipoCliente,
                    telCasa,
                    telOfna,
                    celular,
                    email,
                    fechaAlta,
                    fechaNacimiento,
                    fechaModificacion,
                    genero,
                    estadoCivil,
                    status,
                    etiquetas
                FROM clientes
                ORDER BY id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $clientes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = count($clientes);

        if ($total === 0) {
            $this->log('info', 'Clientes', null, 'Sincronización', 'success',
                'No hay clientes para sincronizar', $configId);
            return;
        }

        $this->log('info', 'Clientes', null, 'Sincronización', 'success',
            "Procesando {$total} clientes", $configId);

        $summary['clientes'] = [
            'created'    => 0,
            'updated'    => 0,
            'no_changes' => 0,
            'skipped'    => 0,
            'errors'     => 0,
        ];

        foreach ($clientes as $clienteExterno) {
            try {
                $this->syncCliente($clienteExterno, $configId, $summary);
            } catch (\Exception $e) {
                $summary['clientes']['errors']++;
                $id = $clienteExterno['id'] ?? 'Unknown';
                $this->log('error', 'Clientes', (string)$id, "ID {$id}", 'error',
                    'Error: ' . $e->getMessage(), $configId);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        $this->log('info', 'Clientes', null, 'Resumen Clientes', 'success',
            "Creados: {$summary['clientes']['created']} | " .
            "Actualizados: {$summary['clientes']['updated']} | " .
            "Sin cambios: {$summary['clientes']['no_changes']} | " .
            "Omitidos: {$summary['clientes']['skipped']} | " .
            "Errores: {$summary['clientes']['errors']} | " .
            "Tiempo: {$elapsed}s", $configId);
    }

    // -------------------------------------------------------------------------
    // Lógica interna
    // -------------------------------------------------------------------------

    private function syncCliente(array $ext, string $configId, array &$summary): void
    {
        if (empty($ext['id'])) {
            $summary['clientes']['skipped']++;
            $this->log('info', 'Clientes', null, 'Unknown', 'warning',
                'Cliente omitido: sin ID', $configId);
            return;
        }

        $clienteId = (string)$ext['id'];
        $cliente   = $this->entityManager->getEntityById('Clientes', $clienteId);

        if (!$cliente) {
            $this->createCliente($ext, $clienteId, $configId, $summary);
        } else {
            $this->updateCliente($cliente, $ext, $configId, $summary);
        }
    }

    private function createCliente(
        array  $ext,
        string $clienteId,
        string $configId,
        array  &$summary
    ): void {
        $data = $this->prepareClienteData($ext);

        try {
            $cliente = $this->entityManager->getNewEntity('Clientes');
            $cliente->set('id', $clienteId);
            $cliente->set($data);

            $this->entityManager->saveEntity($cliente);

            $summary['clientes']['created']++;
            $displayName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
            $this->log('created', 'Clientes', $clienteId, $displayName, 'success',
                'Cliente creado', $configId);

        } catch (\Exception $e) {
            $summary['clientes']['errors']++;
            $this->log('error', 'Clientes', $clienteId, "ID {$clienteId}", 'error',
                'Error al crear: ' . $e->getMessage(), $configId);
            throw $e;
        }
    }

    private function updateCliente(
        $cliente,
        array  $ext,
        string $configId,
        array  &$summary
    ): void {
        $data    = $this->prepareClienteData($ext);
        $changes = [];

        foreach ($data as $field => $newValue) {
            $currentValue = $cliente->get($field);
            $currentNorm  = StringUtils::normalize($currentValue);
            $newNorm      = StringUtils::normalize($newValue);

            if ($currentNorm !== $newNorm) {
                $cliente->set($field, $newValue);
                $changes[] = $field;
            }
        }

        if (!empty($changes)) {
            try {
                $this->entityManager->saveEntity($cliente);

                $summary['clientes']['updated']++;
                $displayName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                $this->log('updated', 'Clientes', $cliente->getId(), $displayName, 'success',
                    'Cliente actualizado: ' . implode(', ', $changes), $configId);

            } catch (\Exception $e) {
                $summary['clientes']['errors']++;
                $this->log('error', 'Clientes', $cliente->getId(), "ID {$cliente->getId()}", 'error',
                    'Error al actualizar: ' . $e->getMessage(), $configId);
                throw $e;
            }
        } else {
            $summary['clientes']['no_changes']++;
        }
    }

    private function prepareClienteData(array $ext): array
    {
        // Nombre completo para el campo "name" (display)
        $firstName = StringUtils::capitalizeWords($ext['nombre'] ?? null) ?? '';
        $lastName  = StringUtils::combineApellidos(
            $ext['apellidoP'] ?? null,
            $ext['apellidoM'] ?? null
        ) ?? '';
        $fullName = trim("{$firstName} {$lastName}");

        // Genero: normalizar a "m" / "f" / ""
        $generoRaw = mb_strtolower(trim($ext['genero'] ?? ''), 'UTF-8');
        $generoMap = [
            'masculino' => 'm',
            'femenino'  => 'f',
            'm'         => 'm',
            'f'         => 'f',
        ];
        $genero = $generoMap[$generoRaw] ?? '';

        $data = [
            'name'             => $fullName,
            'firstName'        => $firstName,
            'lastName'         => $lastName,
            'titulo'           => $ext['titulo'] ?? null,
            'tipoCliente'      => $ext['tipoCliente'] ?? '',
            'genero'           => $genero,
            'estadoCivil'      => $ext['estadoCivil'] ?? null,
            'status'           => $ext['status'] ?? 'enCartera',
            'celular'          => $ext['celular'] ?? null,
            'telCasa'          => $ext['telCasa'] ?? null,
            'telOfna'          => $ext['telOfna'] ?? null,
            'etiquetas'        => $ext['etiquetas'] ?? null,
        ];

        // Email (tipo email nativo de EspoCRM)
        if (!empty($ext['email'])) {
            $data['emailAddress'] = StringUtils::toLowerCase($ext['email']);
        }

        // Fechas
        if (!empty($ext['fechaAlta'])) {
            $data['fechaAlta'] = $ext['fechaAlta'];
        }
        if (!empty($ext['fechaNacimiento'])) {
            $data['fechaNacimiento'] = $ext['fechaNacimiento'];
        }
        if (!empty($ext['fechaModificacion'])) {
            $data['fechaModificacion'] = $ext['fechaModificacion'];
        }

        // Links opcionales (solo si tienen valor válido)
        if (!empty($ext['idAfiliados'])) {
            $data['oficinaId'] = (string)$ext['idAfiliados'];
        }
        if (!empty($ext['idAsesor'])) {
            $data['asesorId'] = (string)$ext['idAsesor'];
        }

        return $data;
    }
}
