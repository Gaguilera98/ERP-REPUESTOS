<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pools híbridos
    |--------------------------------------------------------------------------
    |
    | Cada pool es una conexión de config/database.php que aloja muchos tenants,
    | separados por tenant_id. Un pool nuevo se agrega aquí y en database.php;
    | los tenants nuevos apuntan a ese nombre en tenants.db_pool.
    |
    | tables: tablas del pool que tienen tenant_id. Al borrar un tenant se
    | limpian en este orden (hijas antes que padres).
    |
    */

    'pools' => [
        'pool_shared_1' => [
            'label' => 'Pool compartido 1',
        ],
    ],

    'tables' => [
        'users',
    ],

];
