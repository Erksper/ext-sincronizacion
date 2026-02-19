<div class="panel panel-default">
    <div class="panel-heading">
        <h4 class="panel-title">Panel de Sincronización</h4>
    </div>
    <div class="panel-body">
        <!-- Sección: Usuarios y Teams -->
        <div class="sync-section">
            <h5><i class="fas fa-users"></i> Usuarios y Equipos</h5>
            <div class="button-container">
                <button type="button" class="btn btn-primary" data-action="testConnection">
                    <i class="fas fa-plug"></i> Probar Conexión
                </button>
                <button type="button" class="btn btn-success" data-action="runSync">
                    <i class="fas fa-sync"></i> Sincronizar Usuarios
                </button>
            </div>
        </div>

        <!-- Sección: Propiedades -->
        <div class="sync-section">
            <h5><i class="fas fa-home"></i> Propiedades</h5>
            <div class="button-container">
                <button type="button" class="btn btn-info" data-action="syncPropiedadesAnual">
                    <i class="fas fa-calendar-alt"></i> Sincronizar Propiedades (Últimos 12 Meses)
                </button>
                <button type="button" class="btn btn-warning" data-action="syncPropiedadesCompleta">
                    <i class="fas fa-database"></i> Sincronizar Propiedades (Completa)
                </button>
            </div>
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
                    {{#if syncResult.details}}
                    <div class="sync-details">
                        <hr>
                        <strong>Detalles:</strong>
                        <ul>
                            {{#each syncResult.details}}
                            <li>{{this}}</li>
                            {{/each}}
                        </ul>
                    </div>
                    {{/if}}
                </div>
            </div>
        </div>
        {{/if}}

        {{#if propiedadesResult}}
        <div class="margin-top-2x">
            <h5>Resultado de Sincronización de Propiedades:</h5>
            <div class="alert alert-{{#if propiedadesResult.success}}success{{else}}danger{{/if}}">
                {{propiedadesResult.message}}
                <div class="margin-top">
                    <strong>Tipo:</strong> {{propiedadesResult.tipo}}<br>
                    <strong>Ejecutado:</strong> {{propiedadesResult.timestamp}}
                    {{#if propiedadesResult.details}}
                    <div class="sync-details">
                        <hr>
                        <strong>Resumen:</strong>
                        <ul>
                            {{#each propiedadesResult.details}}
                            <li>{{this}}</li>
                            {{/each}}
                        </ul>
                    </div>
                    {{/if}}
                </div>
            </div>
        </div>
        {{/if}}
    </div>
</div>

<style>
.sync-section {
    margin-bottom: 30px;
    padding: 15px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    background-color: #f9f9f9;
}

.sync-section h5 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #333;
    font-weight: bold;
}

.sync-section h5 i {
    margin-right: 8px;
}

.button-container {
    margin-bottom: 10px;
}

.button-container .btn {
    margin-right: 10px;
    margin-bottom: 10px;
}

.margin-top {
    margin-top: 10px;
}

.margin-top-2x {
    margin-top: 20px;
}

.sync-details {
    margin-top: 10px;
}

.sync-details ul {
    margin-bottom: 0;
    padding-left: 20px;
}

.sync-details li {
    margin-bottom: 5px;
}
</style>
