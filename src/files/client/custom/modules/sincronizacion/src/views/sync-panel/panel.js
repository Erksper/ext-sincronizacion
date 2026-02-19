define('sincronizacion:views/sync-panel/panel', ['view'], function (Dep) {

    return Dep.extend({

        template: 'sincronizacion:sync-panel/panel',

        setup: function () {
            Dep.prototype.setup.call(this);
        },

        data: function () {
            return {
                testResult: this.testResult,
                syncResult: this.syncResult,
                propiedadesResult: this.propiedadesResult
            };
        },

        events: {
            'click [data-action="testConnection"]': function (e) {
                e.preventDefault();
                this.actionTestConnection();
            },
            'click [data-action="runSync"]': function (e) {
                e.preventDefault();
                this.actionRunSync();
            },
            'click [data-action="syncPropiedadesAnual"]': function (e) {
                e.preventDefault();
                this.actionSyncPropiedades('anual');
            },
            'click [data-action="syncPropiedadesCompleta"]': function (e) {
                e.preventDefault();
                this.actionSyncPropiedades('completa');
            }
        },

        actionTestConnection: function () {
            var $btn = this.$el.find('[data-action="testConnection"]');
            $btn.prop('disabled', true);
            
            Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

            Espo.Ajax
                .getRequest('SyncPanel/action/testConnection')
                .then(function (response) {
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        this.testResult = {
                            success: true,
                            config: response.data.config,
                            userCount: response.data.userCount,
                            teamCount: response.data.teamCount,
                            message: response.message
                        };
                        Espo.Ui.success(response.message);
                    } else {
                        this.testResult = {
                            success: false,
                            message: response.message
                        };
                        Espo.Ui.error(response.message);
                    }
                    
                    this.reRender();
                }.bind(this))
                .catch(function (xhr) {
                    $btn.prop('disabled', false);
                    var errorMsg = 'Error de conexión';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Espo.Ui.error(errorMsg);
                    console.error('Error testConnection:', xhr);
                }.bind(this));
        },

        actionRunSync: function () {
            this.confirm('¿Ejecutar sincronización de usuarios y equipos ahora?', function () {
                var $btn = this.$el.find('[data-action="runSync"]');
                $btn.prop('disabled', true);
                
                Espo.Ui.notify('Ejecutando sincronización de usuarios...', 'info');

                Espo.Ajax
                    .postRequest('SyncPanel/action/runSync')
                    .then(function (response) {
                        $btn.prop('disabled', false);
                        
                        if (response.success) {
                            this.syncResult = {
                                success: true,
                                message: response.message,
                                timestamp: new Date().toLocaleString(),
                                details: response.details || null
                            };
                            Espo.Ui.success(response.message);
                        } else {
                            this.syncResult = {
                                success: false,
                                message: response.message,
                                timestamp: new Date().toLocaleString(),
                                details: response.details || null
                            };
                            Espo.Ui.error(response.message);
                        }
                        
                        this.reRender();
                    }.bind(this))
                    .catch(function (xhr) {
                        $btn.prop('disabled', false);
                        var errorMsg = 'Error ejecutando sincronización';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                        console.error('Error runSync:', xhr);
                    }.bind(this));
            }.bind(this));
        },

        actionSyncPropiedades: function (tipo) {
            var mensaje = tipo === 'anual' 
                ? '¿Sincronizar propiedades de los últimos 12 meses?' 
                : '¿Sincronizar TODAS las propiedades? (Puede tardar varios minutos)';
            
            var tipoTexto = tipo === 'anual' ? 'Anual (12 meses)' : 'Completa';
            
            this.confirm(mensaje, function () {
                var $btn = this.$el.find('[data-action="syncPropiedades' + 
                    (tipo === 'anual' ? 'Anual' : 'Completa') + '"]');
                $btn.prop('disabled', true);
                
                Espo.Ui.notify('Sincronizando propiedades (' + tipo + ')... Esto puede tardar varios minutos.', 'info', 0);

                Espo.Ajax
                    .postRequest('SyncPanel/action/syncPropiedades', {
                        tipo: tipo
                    })
                    .then(function (response) {
                        $btn.prop('disabled', false);
                        Espo.Ui.notify(false);
                        
                        if (response.success) {
                            this.propiedadesResult = {
                                success: true,
                                tipo: tipoTexto,
                                message: response.message,
                                timestamp: new Date().toLocaleString(),
                                details: response.details || null
                            };
                            Espo.Ui.success(response.message);
                        } else {
                            this.propiedadesResult = {
                                success: false,
                                tipo: tipoTexto,
                                message: response.message,
                                timestamp: new Date().toLocaleString(),
                                details: response.details || null
                            };
                            Espo.Ui.error(response.message);
                        }
                        
                        this.reRender();
                    }.bind(this))
                    .catch(function (xhr) {
                        $btn.prop('disabled', false);
                        Espo.Ui.notify(false);
                        
                        var errorMsg = 'Error sincronizando propiedades';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        
                        this.propiedadesResult = {
                            success: false,
                            tipo: tipoTexto,
                            message: errorMsg,
                            timestamp: new Date().toLocaleString()
                        };
                        
                        Espo.Ui.error(errorMsg);
                        console.error('Error syncPropiedades:', xhr);
                        this.reRender();
                    }.bind(this));
            }.bind(this));
        }

    });
});
