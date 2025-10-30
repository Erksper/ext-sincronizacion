<div class="panel panel-default">
    <div class="panel-heading">
        <h4 class="panel-title">Panel de Sincronización</h4>
    </div>
    <div class="panel-body">
        <div class="button-container">
            <button type="button" class="btn btn-primary" data-action="testConnection">
                Probar Conexión
            </button>
            <button type="button" class="btn btn-success" data-action="runSync">
                Ejecutar Sincronización
            </button>
        </div>

        {{#if testResult}}
        <div class="margin-top-2x">
            <h5>Resultado de Prueba de Conexión:</h5>
            <div class="alert alert-{{#if testResult.success}}success{{else}}danger{{/if}}">
                {{testResult.message}}
                {{#if testResult.success}}
                <div class="margin-top">
                    <strong>Configuración:</strong> {{testResult.config}}<br>
                    <strong>Usuarios encontrados:</strong> {{testResult.userCount}}<br>
                    <strong>Equipos encontrados:</strong> {{testResult.teamCount}}
                </div>
                {{/if}}
            </div>
        </div>
        {{/if}}

        {{#if syncResult}}
        <div class="margin-top-2x">
            <h5>Resultado de Sincronización:</h5>
            <div class="alert alert-{{#if syncResult.success}}success{{else}}danger{{/if}}">
                {{syncResult.message}}
                <div class="margin-top">
                    <strong>Ejecutado:</strong> {{syncResult.timestamp}}
                </div>
            </div>
        </div>
        {{/if}}
    </div>
</div>

<style>
.button-container {
    margin-bottom: 20px;
}
.button-container .btn {
    margin-right: 10px;
}
.margin-top {
    margin-top: 10px;
}
.margin-top-2x {
    margin-top: 20px;
}
</style>