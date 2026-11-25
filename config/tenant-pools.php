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
        'inventario_inicial_detalles',
        'importaciones_productos',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'roles',
        'pago_aplicaciones',
        'pagos',
        'caja_chica_movimientos',
        'caja_chica_arqueos',
        'caja_chica_fondos',
        'categorias_gastos',
        'user_sucursales',
        'nota_credito_detalles',
        'notas_credito_devoluciones',
        'venta_detalles',
        'correcciones_venta',
        'ampliaciones_reserva',
        'ventas',
        'caja_sesion_medios',
        'cajas_sesiones',
        'cajas_dias',
        'ajuste_detalles',
        'ajustes_inventario',
        'traslado_detalles',
        'traslados',
        'correcciones_costo',
        'movimientos_inventario',
        'orden_compra_detalles',
        'tipos_cambio',
        'ordenes_compra',
        'proveedor_contactos',
        'proveedores',
        'trabajadores',
        'inventario_stocks',
        'productos_variantes',
        'condiciones_producto',
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
