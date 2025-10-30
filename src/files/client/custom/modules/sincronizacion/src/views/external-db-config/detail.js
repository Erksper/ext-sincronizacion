define('sincronizacion:views/external-db-config/detail', ['views/detail'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);
        },

        actionRunSync: function () {
            var id = this.model.id;

            this.confirm(this.translate('Are you sure?'), function () {
                Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

                Espo.Ajax
                    .postRequest('ExternalDbConfig/action/runSync', {
                        id: id
                    })
                    .then(function (response) {
                        if (response.success) {
                            Espo.Ui.success(response.message);
                            
                            setTimeout(function () {
                                this.model.fetch();
                            }.bind(this), 2000);
                        } else {
                            Espo.Ui.error(response.message);
                        }
                    }.bind(this))
                    .catch(function () {
                        Espo.Ui.error(this.translate('Error'));
                    }.bind(this));
            }.bind(this));
        },

        actionTestConnection: function () {
            var id = this.model.id;

            Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

            Espo.Ajax
                .postRequest('ExternalDbConfig/action/testConnection', {
                    id: id
                })
                .then(function (response) {
                    if (response.success) {
                        var message = response.message;
                        if (response.userCount !== undefined) {
                            message += '<br><br>Usuarios activos encontrados: <strong>' + response.userCount + '</strong>';
                        }
                        
                        Espo.Ui.success(message, true);
                    } else {
                        Espo.Ui.error(response.message, true);
                    }
                }.bind(this))
                .catch(function () {
                    Espo.Ui.error(this.translate('Error'));
                }.bind(this));
        }

    });
});