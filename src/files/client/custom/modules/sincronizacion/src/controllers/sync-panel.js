define('sincronizacion:controllers/sync-panel', ['controllers/base'], function (Dep) {

    return Dep.extend({

        // Cuando se accede a #SyncPanel sin acción específica
        actionIndex: function () {
            this.actionList();
        },

        // La acción principal que carga la vista
        actionList: function () {
            this.main('sincronizacion:views/sync-panel/panel', {
                scope: 'SyncPanel'
            });
        }

    });
});