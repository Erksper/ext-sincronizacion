<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Traits\Loggable;

class PropiedadHandler
{
    use Loggable;

    private EntityManager $entityManager;

    // Campos numéricos (float con 2 decimales)
    private array $numericFields = [
        'comision',
        'precioEnContrato',
        'precioVenta',
        'precioRenta',
        'precioCierre',
        'm2T',
        'm2C',
        'edad',
    ];

    // Campos booleanos
    private array $booleanFields = [
        'enInternet',
        'compartidoConC21',
        'referidoConC21',
    ];

    // Campos de dirección (se limpian de puntuación)
    private array $addressFields = [
        'calle',
        'numero',
        'municipio',
        'urbanizacion',
        'ciudad',
        'estado',
        'pais',
        'infoExtraPrecio',
    ];

    // Campos URL (sin normalización adicional)
    private array $urlFields = [
        'linkPublico',
        'link21Online',
    ];

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function syncPropiedades(
        \PDO   $pdo,
        string $syncType,
        string $configId,
        array  &$summary
    ): void {
        $startTime = microtime(true);

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

        $pageSize    = 1000;
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
            enInternet, m2T, m2C, edad,
            clave, tipoCierre, fechaCV, fechaEstimadaCierre, fechaCierre,
            idAsesorCierre, operacionCompartida, idAfiliadosCompartida,
            idClientesComprador, idClientesVendedor,
            compartidoCon, compartidoConC21, referidoConC21,
            idAfiliadosReferida, precioCierre
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

        $elapsed = round(microtime(true) - $startTime, 2);

        $this->log('info', 'Propiedades', null, 'Resumen Final', 'success',
            "Creadas: {$summary['propiedades']['created']} | " .
            "Actualizadas: {$summary['propiedades']['updated']} | " .
            "Sin cambios: {$summary['propiedades']['no_changes']} | " .
            "Omitidas: {$summary['propiedades']['skipped']} | " .
            "Errores: {$summary['propiedades']['errors']} | " .
            "Tiempo: {$elapsed}s", $configId);
    }

    // -------------------------------------------------------------------------
    // Lógica interna
    // -------------------------------------------------------------------------

    private function syncPropiedad(
        array  $propiedadExterna,
        string $configId,
        array  &$summary
    ): void {
        if (!$this->validatePropiedadData($propiedadExterna, $summary, $configId)) {
            return;
        }

        $propiedadId = (string)$propiedadExterna['id'];
        $propiedad   = $this->entityManager->getEntityById('Propiedades', $propiedadId);

        if (!$propiedad) {
            $this->createPropiedad($propiedadExterna, $propiedadId, $configId, $summary);
        } else {
            $this->updatePropiedad($propiedad, $propiedadExterna, $configId, $summary);
        }
    }

    private function validatePropiedadData(array $propiedadExterna, array &$summary, string $configId): bool
    {
        $camposObligatorios = [
            'id'                => 'ID',
            'idAfiliados'       => 'Oficina (idAfiliados)',
            'fechaAlta'         => 'Fecha de Alta',
            'tipoOperacion'     => 'Tipo de Operación',
            'tipoPropiedad'     => 'Tipo de Propiedad',
            'subtipoPropiedad'  => 'Subtipo de Propiedad',
            'tipoDeContrato'    => 'Tipo de Contrato',
            'status'            => 'Estado',
            'idAsesorExclusiva' => 'Asesor Exclusiva',
        ];

        foreach ($camposObligatorios as $campo => $nombre) {
            $valor = $propiedadExterna[$campo] ?? null;
            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
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
        array  $propiedadExterna,
        string $propiedadId,
        string $configId,
        array  &$summary
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

            $this->assignTeamsFromAssignedUser($propiedad, $propiedadData['assignedUserId']);

            $summary['propiedades']['created']++;
            $this->log('created', 'Propiedades', $propiedadId, $propiedadData['name'], 'success',
                'Propiedad creada', $configId);

        } catch (\Exception $e) {
            $summary['propiedades']['errors']++;
            $this->log('error', 'Propiedades', $propiedadId, "ID {$propiedadId}", 'error',
                'Error al crear: ' . $e->getMessage(), $configId);
            throw $e;
        }
    }

    private function updatePropiedad(
        $propiedad,
        array  $propiedadExterna,
        string $configId,
        array  &$summary
    ): void {
        $propiedadData = $this->preparePropiedadData($propiedadExterna);

        if (!$propiedadData) {
            return;
        }

        $changes     = [];
        $needsUpdate = false;

        foreach ($propiedadData as $field => $newValue) {
            $currentValue = $propiedad->get($field);
            $currentNorm  = $this->normalizeValue($currentValue, $field);
            $newNorm      = $this->normalizeValue($newValue, $field);

            if ($currentNorm !== $newNorm) {
                $propiedad->set($field, $newValue);
                $needsUpdate = true;
                $changes[]   = $field;
            }
        }

        if ($needsUpdate) {
            try {
                $this->entityManager->saveEntity($propiedad);
                $this->assignTeamsFromAssignedUser($propiedad, $propiedadData['assignedUserId']);

                $summary['propiedades']['updated']++;
                $this->log('updated', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'success',
                    'Propiedad actualizada: ' . implode(', ', $changes), $configId);

            } catch (\Exception $e) {
                $summary['propiedades']['errors']++;
                $this->log('error', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'error',
                    'Error al actualizar: ' . $e->getMessage(), $configId);
                throw $e;
            }
        } else {
            // Verificar si cambiaron los equipos del asesor aunque no haya otros cambios
            $currentTeamIds = $this->getCurrentTeamIds($propiedad);
            $newTeamIds     = $this->getAssignedUserTeamIds($propiedadData['assignedUserId']);

            if ($this->teamListsDiffer($currentTeamIds, $newTeamIds)) {
                $this->assignTeamsFromAssignedUser($propiedad, $propiedadData['assignedUserId']);
                $summary['propiedades']['updated']++;
                $this->log('updated', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'success',
                    'Equipos actualizados', $configId);
            } else {
                $summary['propiedades']['no_changes']++;
            }
        }
    }

    private function preparePropiedadData(array $ext): ?array
    {
        $tipoOperacion = $ext['tipoOperacion'] ?? 'N/A';
        $tipoPropiedad = $ext['tipoPropiedad'] ?? 'N/A';
        $urbanizacion  = $ext['colonia2'] ?? $ext['colonia'] ?? 'Sin especificar';
        $name          = "{$tipoOperacion} - {$tipoPropiedad} - {$urbanizacion}";

        $fechaAlta   = !empty($ext['fechaAlta']) ? $ext['fechaAlta'] : date('Y-m-d H:i:s');
        $propiedadId = (string)$ext['id'];

        // Campos base (obligatorios)
        $data = [
            'name'              => $name,
            'idOficinaId'       => (string)$ext['idAfiliados'],
            'fechaAlta'         => $fechaAlta,
            'tipoOperacion'     => $ext['tipoOperacion'],
            'tipoPropiedad'     => $ext['tipoPropiedad'],
            'subTipoPropiedad'  => $ext['subtipoPropiedad'],
            'tipoDeContrato'    => $ext['tipoDeContrato'],
            'status'            => $ext['status'],
            'idAsesorExclusivaId' => (string)$ext['idAsesorExclusiva'],
            'assignedUserId'    => (string)$ext['idAsesorExclusiva'],
            'linkPublico'       => 'https://www.century21.com.ve/v/resultados/ordenado-por_relevancia/por_' . $propiedadId,
            'link21Online'      => 'https://venezuela.21online.lat/propiedades/editar/' . $propiedadId,
        ];

        // Campos opcionales originales
        $camposOpcionalesOriginales = [
            'fechaModificacion' => 'fechaModificacion',
            'comision'          => 'comision',
            'precioEnContrato'  => 'precioEnContrato',
            'monedaEnContrato'  => 'monedaEnContrato',
            'calle'             => 'calle',
            'numero'            => 'numero',
            'municipio'         => 'colonia',
            'urbanizacion'      => 'colonia2',
            'ciudad'            => 'municipio',
            'estado'            => 'estado',
            'pais'              => 'pais',
            'precioVenta'       => 'precioVenta',
            'precioRenta'       => 'precioRenta',
            'infoExtraPrecio'   => 'infoExtraPrecio',
            'moneda'            => 'moneda',
            'enInternet'        => 'enInternet',
            'm2T'               => 'm2T',
            'm2C'               => 'm2C',
            'edad'              => 'edad',
        ];

        foreach ($camposOpcionalesOriginales as $campoEspo => $campo21) {
            if (isset($ext[$campo21]) && $ext[$campo21] !== '') {
                $data[$campoEspo] = $ext[$campo21];
            }
        }

        // Nuevos campos opcionales de cierre
        $nuevosCampos = [
            'clave'                  => 'clave',
            'tipoCierre'             => 'tipoCierre',
            'fechaCV'                => 'fechaCV',
            'fechaEstimadaCierre'    => 'fechaEstimadaCierre',
            'fechaCierre'            => 'fechaCierre',
            'operacionCompartida'    => 'operacionCompartida',
            'compartidoCon'          => 'compartidoCon',
        ];

        foreach ($nuevosCampos as $campoEspo => $campoExt) {
            if (isset($ext[$campoExt]) && $ext[$campoExt] !== '') {
                $data[$campoEspo] = $ext[$campoExt];
            }
        }

        // Booleanos nuevos (siempre se mapean, incluso si son 0)
        $data['compartidoConC21'] = !empty($ext['compartidoConC21']);
        $data['referidoConC21']   = !empty($ext['referidoConC21']);

        // precioCierre como currency
        if (isset($ext['precioCierre']) && $ext['precioCierre'] !== '') {
            $data['precioCierre'] = $ext['precioCierre'];
        }

        // Links opcionales nuevos (solo si tienen valor entero válido)
        if (!empty($ext['idAsesorCierre'])) {
            $data['idAsesorCierreId'] = (string)$ext['idAsesorCierre'];
        }
        if (!empty($ext['idAfiliadosCompartida'])) {
            $data['idAfiliadosCompartidaId'] = (string)$ext['idAfiliadosCompartida'];
        }
        if (!empty($ext['idAfiliadosReferida'])) {
            $data['idAfiliadosReferidaId'] = (string)$ext['idAfiliadosReferida'];
        }
        // Los links a Clientes se mapean directamente por ID
        if (!empty($ext['idClientesComprador'])) {
            $data['idClientesCompradorId'] = (string)$ext['idClientesComprador'];
        }
        if (!empty($ext['idClientesVendedor'])) {
            $data['idClientesVendedorId'] = (string)$ext['idClientesVendedor'];
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers de equipos
    // -------------------------------------------------------------------------

    private function assignTeamsFromAssignedUser($propiedad, string $assignedUserId): void
    {
        $teamIds = $this->getAssignedUserTeamIds($assignedUserId);

        if (!empty($teamIds)) {
            $propiedad->set('teamsIds', $teamIds);
            $this->entityManager->saveEntity($propiedad);
        }
    }

    private function getAssignedUserTeamIds(string $assignedUserId): array
    {
        $asesor = $this->entityManager->getEntityById('User', $assignedUserId);
        if (!$asesor) {
            return [];
        }

        $teamIds = [];
        $teams   = $asesor->get('teams');
        if ($teams) {
            foreach ($teams as $team) {
                $teamIds[] = $team->getId();
            }
        }

        $defaultTeamId = $asesor->get('defaultTeamId');
        if ($defaultTeamId && !in_array($defaultTeamId, $teamIds)) {
            $teamIds[] = $defaultTeamId;
        }

        return $teamIds;
    }

    private function getCurrentTeamIds($propiedad): array
    {
        $currentTeams = $propiedad->get('teams');
        $ids          = [];
        if ($currentTeams) {
            foreach ($currentTeams as $team) {
                $ids[] = $team->getId();
            }
        }
        return $ids;
    }

    private function teamListsDiffer(array $list1, array $list2): bool
    {
        $list1 = array_unique(array_map('strval', $list1));
        $list2 = array_unique(array_map('strval', $list2));
        sort($list1);
        sort($list2);
        return $list1 !== $list2;
    }

    // -------------------------------------------------------------------------
    // Normalización de valores para comparación
    // -------------------------------------------------------------------------

    private function normalizeValue($value, string $field): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array($field, $this->booleanFields)) {
            return $value ? '1' : '0';
        }

        if (in_array($field, $this->numericFields)) {
            return number_format((float)$value, 2, '.', '');
        }

        if (in_array($field, $this->addressFields)) {
            return StringUtils::normalizeAddress($value);
        }

        if (in_array($field, $this->urlFields)) {
            return trim((string)$value);
        }

        return StringUtils::normalize($value);
    }
}
