define('sincronizacion:controllers/sync-panel', ['controller'], function (Dep) {

    return Dep.extend({

        defaultAction: 'index',

        index: function () {
            this.main('sincronizacion:views/sync-panel/panel');
        }

    });
});