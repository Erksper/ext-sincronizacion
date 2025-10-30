define('custom:views/sync-panel/panel', ['view'], function (Dep) {

    return Dep.extend({

        template: 'custom:sync-panel/panel',

        data: function () {
            return {
                testResult: this.testResult,
                syncResult: this.syncResult
            };
        },

        events: {
            'click [data-action="testConnection"]': function () {
                this.actionTestConnection();
            },
            'click [data-action="runSync"]': function () {
                this.actionRunSync();
            }
        },

        actionTestConnection: function () {
            this.$el.find('[data-action="testConnection"]').prop('disabled', true);
            
            Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

            Espo.Ajax
                .getRequest('SyncPanel/action/testConnection')
                .then(function (response) {
                    this.$el.find('[data-action="testConnection"]').prop('disabled', false);
                    
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
                .catch(function () {
                    this.$el.find('[data-action="testConnection"]').prop('disabled', false);
                    Espo.Ui.error(this.translate('Error'));
                }.bind(this));
        },

        actionRunSync: function () {
            this.confirm('¿Ejecutar sincronización ahora?', function () {
                this.$el.find('[data-action="runSync"]').prop('disabled', true);
                
                Espo.Ui.notify('Ejecutando sincronización...');

                Espo.Ajax
                    .postRequest('SyncPanel/action/runSync')
                    .then(function (response) {
                        this.$el.find('[data-action="runSync"]').prop('disabled', false);
                        
                        if (response.success) {
                            this.syncResult = {
                                success: true,
                                message: response.message,
                                timestamp: new Date().toLocaleString()
                            };
                            Espo.Ui.success(response.message);
                        } else {
                            this.syncResult = {
                                success: false,
                                message: response.message,
                                timestamp: new Date().toLocaleString()
                            };
                            Espo.Ui.error(response.message);
                        }
                        
                        this.reRender();
                    }.bind(this))
                    .catch(function () {
                        this.$el.find('[data-action="runSync"]').prop('disabled', false);
                        Espo.Ui.error(this.translate('Error'));
                    }.bind(this));
            }.bind(this));
        }

    });
});