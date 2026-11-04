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
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'roles',
        'pago_aplicaciones',
        'pagos',
        'venta_impuestos',
        'venta_detalles',
        'ventas',
        'caja_sesion_medios',
        'cajas_sesiones',
        'cajas_dias',
        'movimientos_inventario',
        'orden_compra_detalles',
        'ordenes_compra',
        'proveedor_contactos',
        'proveedores',
        'trabajadores',
        'inventario_stocks',
        'productos_variantes',
        'producto_unidades',
        'productos_maestros',
        'marcas',
        'categoria_atributos',
        'categorias',
        'unidades_medida',
        'cliente_documentos',
        'tipo_documento_atributos',
        'clientes',
        'tipos_documento_identidad',
        'impuestos',
        'sucursal_almacenes',
        'negocio_configuracion',
        'sucursales',
        'almacenes',
        'users',
    ],

];
