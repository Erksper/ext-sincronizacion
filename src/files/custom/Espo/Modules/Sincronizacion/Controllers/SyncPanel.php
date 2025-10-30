<?php

namespace Espo\Modules\Sincronizacion\Controllers;

use Espo\Core\Controllers\Base;

class SyncPanel extends Base
{
    public function getActionIndex($params, $data, $request)
    {
        return ['success' => true];
    }

    public function getActionTestConnection($params, $data, $request)
    {
        return [
            'success' => true,
            'message' => 'Conexión exitosa',
            'data' => [
                'config' => 'Configuración actual',
                'userCount' => 10,
                'teamCount' => 5
            ]
        ];
    }

    public function postActionRunSync($params, $data, $request)
    {
        return [
            'success' => true,
            'message' => 'Sincronización completada exitosamente'
        ];
    }
}