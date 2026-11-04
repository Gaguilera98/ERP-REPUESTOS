---
name: Fases ERP mostrador
overview: Construir el ERP por fases sobre el panel Filament del negocio, sin crear todas las tablas de golpe. Catálogos, compras y caja son recursos Filament. La venta es una pantalla Livewire dentro de ese panel. Cada fase deja reglas de esstructuraybasecondiciones.md cubiertas y probadas antes de pasar a la siguiente.
todos:
  - id: fase-1-locales
    content: "Fase 1: Shield por negocio, configuración, sucursales, almacenes y pivote"
    status: completed
  - id: fase-2-catalogo
    content: "Fase 2: unidades, categorías, atributos, marcas, productos, variantes y stock en cero"
    status: completed
  - id: fase-3-compras
    content: "Fase 3: proveedores, compras, kardex y PPP con confirmación de costo"
    status: completed
  - id: fase-4-caja
    content: "Fase 4: clientes, documentos dinámicos, impuestos y apertura/cierre de caja"
    status: completed
  - id: fase-5-venta
    content: "Fase 5: mostrador Livewire de contado, código VNT y compra flash"
    status: completed
  - id: fase-6-credito
    content: "Fase 6: seña, fiado, adelanto y aplicaciones de pago"
    status: pending
  - id: fase-7-devolucion
    content: "Fase 7: notas de crédito y devolución Livewire"
    status: pending
  - id: fase-8-factura
    content: "Fase 8: talonario y factura manual"
    status: pending
  - id: fase-9-ajustes
    content: "Fase 9: ajustes, conteo, traslados y corrección de costo"
    status: pending
  - id: fase-10-cajachica
    content: "Fase 10: categorías Filament, vale Livewire en la caja y candado de cierre"
    status: pending
  - id: fase-11-dolar
    content: "Fase 11: tipo de cambio y cola de precios en dólares"
    status: pending
isProject: false
---

# Construcción por fases

La base ya está: tenancy híbrido, panel `admin` y panel `negocio` en [app/Providers/Filament/NegocioPanelProvider.php](app/Providers/Filament/NegocioPanelProvider.php). El pool solo tiene `users` ([config/tenant-pools.php](config/tenant-pools.php)). El contrato es [esstructuraybasecondiciones.md](esstructuraybasecondiciones.md). No se reescribe esa base.

Cada fase agrega migraciones en `database/migrations/tenant`, modelos con `BelongsToTenantPool`, el único `(tenant_id, id)` y las foreign keys compuestas. Las tablas nuevas entran en `config/tenant-pools.php` con las hijas antes que los padres. Los tests son Pest y no tocan Postgres local.

```mermaid
flowchart LR
  f1[Fase1_Locales] --> f2[Fase2_Catalogo]
  f2 --> f3[Fase3_Compras_PPP]
  f3 --> f4[Fase4_Caja]
  f4 --> f5[Fase5_Venta_contado]
  f5 --> f6[Fase6_Sena_fiado]
  f6 --> f7[Fase7_Devoluciones]
  f7 --> f8[Fase8_Factura]
  f5 --> f9[Fase9_Ajustes]
  f4 --> f10[Fase10_Caja_chica]
  f2 --> f11[Fase11_Dolar]
```

## Dónde va cada pantalla

- Recursos Filament en `app/Filament/Negocio/Resources`: sucursales, almacenes, categorías, productos, clientes, tipos de documento de identidad, proveedores, compras, talonarios, ajustes, traslados, categorías de gasto, impuestos, tipo de cambio.
- Abrir/cerrar caja es una página Filament (`CajaDelNegocio`), no un resource de listado completo ni el mostrador.
- El mostrador es `MostradorDelNegocio` + Livewire `app/Livewire/Negocio/Venta/Mostrador`: buscador, líneas, flash, cobros y adjuntos.
- La devolución del mismo día usa el mismo estilo Livewire, no un formulario genérico.
- El vale de caja chica tampoco es un resource. Vive en la página de la caja abierta, como un paso corto: monto, concepto, categoría y comprobante. El listado histórico puede ser una tabla Filament de solo lectura.

## Fase 1. Locales, configuración y permisos — hecha

Tablas: `negocio_configuracion`, `sucursales`, `almacenes`, `sucursal_almacenes`. No se agrega columna `rol` en `users`.

Permisos: Filament Shield en el panel `negocio`, con Spatie y equipos. El equipo es el `tenant_id`. Las tablas de roles y permisos viven en el pool, no en la central, y entran en la lista de limpieza. Al crear un negocio se siembran hoy dos roles: `cajero` y `supervisor`. El panel `admin` de la plataforma no usa el `super_admin` de Shield: el rol de plataforma es `admin`.

Reglas: el tenant es el negocio; `tenant_id` en todo el pool; el almacén no tiene `sucursal_id`; la pivote es el único vínculo y `es_principal` vive ahí; un almacén compartido es dos filas de la misma pila; dos saldos distintos son dos almacenes. `moneda` nace en `BOB` y `modo_moneda` en `solo_moneda` *(Pendiente: evaluar migrar `moneda` a `varchar(3)` ISO 4217 para no limitar el SaaS a 3 países)*. El dólar no aparece todavía. Las fases siguientes deben autorizar por **permiso** Shield/Spatie, no por nombre de rol hardcodeado ni por columna en `users`.

Pantalla: resources de sucursales y almacenes, y asignar almacenes a la sucursal. El rol de Shield se asigna al crear el usuario del negocio. El cajero no administra roles.

Tener en cuenta (no está — ver `esstructuraybasecondiciones.md` punto 15 y futuros 11 y 14):
- Plantilla **`encargado`** (matriz: usuarios, roles, sucursales, config + ver todas las sucursales). `supervisor` operativo sin administración del sistema. Roles custom en Shield sin tocar código. No roles por sucursal. No `super_admin` de Shield.
- Pivote `user_sucursales` + **switch de sucursal en el menú** (contexto de operación). Usuarios y roles **fuera** del switch. Hoy cualquiera ve todas las sucursales. No implementarlo en esta fase.

## Fase 2. Catálogo — hecha

Tablas: `unidades_medida`, `categorias`, `categoria_atributos`, `marcas`, `productos_maestros`, `productos_variantes`, `inventario_stocks` en cero. No existe `categorias.plantilla_atributos`. La marca no es un atributo de la categoría: es `marcas.nombre`, único por negocio sin distinguir mayúsculas, y `productos_maestros.marca_id`. En la limpieza del pool, `productos_maestros` sale antes que `marcas`, y `categoria_atributos` antes que `categorias` y que `unidades_medida`.

Reglas: SKU único por negocio; código de barras único si existe y el vacío puede repetirse. La ficha del rubro es `categoria_atributos`: clave, etiqueta, tipo (`decimal`, `texto` o `lista`), obligatorio, orden y `unidad_medida_id` nulo cuando el campo no se mide. No hay una unidad «sin unidad». Los valores van en `atributos_json`: el decimal como número JSON, el texto y la lista como texto, sin copiar la unidad. Índice GIN para la igualdad dentro del negocio y de la categoría. La variante no agrega claves fuera de su categoría. Ubicación en el stock, no en la variante. `precio_venta` y `precio_mayor` en la moneda de la tienda. Las columnas en dólares se crean vacías y no se usan hasta la fase 11.

Pantalla: resource de unidades y de categorías, con los atributos de la ficha en la categoría. Resource de productos con variantes: el formulario arma los campos desde `categoria_atributos` y guarda los valores ya tipados. Stock inicial en cero por almacén. Sin kardex todavía: no se puede “poner cantidad” a mano.

Tener en cuenta (hablarlo después): si el vendedor busca acá y no en el mostrador, ayudarlo a no retrucar. En la tabla/ficha de la variante: copiar SKU (o código de barras) y/o una acción **Agregar al mostrador** que abra el cobro con esa pieza. No cobrar desde el catálogo. Ver `esstructuraybasecondiciones.md` futuro ítem 12.

## Fase 3. Compras y costo promedio — hecha

Tablas: `proveedores`, `proveedor_contactos`, `trabajadores`, `ordenes_compra`, `orden_compra_detalles`, `movimientos_inventario`. Al crear el negocio se inserta el proveedor ocasional, uno solo.

Reglas: el kardex solo se inserta, con stock previo, posterior y usuario; el costo va en `decimal(14,4)`; cada compra guarda su costo y el producto guarda el promedio; antes de guardar, la pantalla muestra el costo por unidad y pide confirmación; la compra no cambia el precio de venta; no hay stock negativo.

Pantalla: resource de compras y de proveedores. Recibir mercadería escribe stock y kardex. La entrada express queda para la fase 5, porque nace desde el mostrador.

## Fase 4. Clientes, impuestos y caja — hecha

Tablas: `clientes`, `tipos_documento_identidad`, `tipo_documento_atributos`, `cliente_documentos`, `impuestos`, `cajas_sesiones`.

Reglas cubiertas:
- Cliente sin moneda propia; `tipo_precio` `menor` | `mayor` (v1; listas estilo Odoo quedan a futuro en el doc maestro).
- `tipo_cliente` persona natural | empresa: persona usa `nombres` + `apellidos` (y se arma `razon_social` para listar); empresa usa `razon_social`.
- Documentos de identidad **configurables** (no un solo `nit_ci`): catálogo por tenant (CI, NIT sembrados al nacer), atributos dinámicos (texto/decimal/lista, obligatorio, identifica), valores en `cliente_documentos.valores_json` + `identidad` única por tipo.
- Impuestos: catálogo con porcentaje; en la venta se copiará el snapshot (fase 5).
- Caja: una sesión abierta por `(tenant, sucursal, user)`; se puede cerrar y abrir otra el mismo día; si hay diferencia, justificación obligatoria; el cierre no mira vales (fase 10).

Pantalla: resources Clientes, Impuestos, Tipos de documento; página Filament Abrir/Cerrar caja (historial corto del cajero).

Arqueo del turno (cierre de 4/5): al cerrar se declara **cada medio** (efectivo contado + QR/transferencia/voucher confirmados). Esperado: efectivo = apertura + cobros − reembolsos; otros = neto del turno. Si algún medio no calza, justificación obligatoria. Snapshot en `caja_sesion_medios`. Todos los cobros del contado cuelgan de `pagos.caja_sesion_id`. Vista supervisor **Cajas del día**: consolidado por sucursal + fecha. El faltante es solo de turnos cerrados; lo abierto queda pendiente de contar. Un turno de otro día se congela y se puede abrir el de hoy. Turno sin ventas se anula. Falta amarrar sucursales a cargo. Egresos de caja chica: fase 10.

## Fase 5. Venta de contado — hecha

Tablas: `ventas`, `venta_detalles`, `venta_impuestos`, `pagos`, `pago_aplicaciones`. `siguiente_numero_venta` ya en configuración.

Reglas cubiertas: código `VNT-`; almacén en pivote de sucursal; lock de stock; contado con caja abierta; descuento %/importe con tope y supervisor; alerta PPP sin bloquear; `nota_venta`; snapshot de impuestos; cobros multi-método (`efectivo`, `qr_externo`, `voucher_tarjeta`, `transferencia`) sin pasarela; adjunto local por pago; factory de handlers de método de pago.

Pantalla: `MostradorDelNegocio` + Livewire `Mostrador`. Compra flash completa (pieza existente o alta al vuelo) con ocasional y `entrada_flash_emergencia`. El descuento visible es el del ticket (derecha, «Agregar descuento»), % o importe, con los topes de configuración. El de línea existe en el detalle y no se muestra.

Tener en cuenta (no está en la pantalla): si el descuento pasa el tope, `CompletarVentaContado` exige `supervisor_descuento_id` y la venta no cierra, pero nadie tipea clave. Pendiente, sin abrir otra fase: (1) dentro del tope, el cajero reingresa su contraseña para dejar huella; (2) pasado el tope, modal con usuario y contraseña de un supervisor del mismo negocio, y se guarda ese id. No alcanza un select de supervisor. El mismo modal sirve después para anular y devolver. Ver `esstructuraybasecondiciones.md` punto 25 y futuro ítem 3. La promo por producto se carga en la ficha de la variante, no acá (futuro ítem 2).

Tener en cuenta: el catálogo puede mandar una variante acá (SKU copiado o «Agregar al mostrador»). El mostrador ya agrega por id (`seleccionarResultado`). Cuando se arme la acción en productos, reutilizar eso. Ver futuro ítem 12.

Tener en cuenta: el select de sucursal del mostrador lista todas las activas. Cuando exista la asignación (fase 1 / futuro ítem 11), acá solo van las del usuario y la que eligió al iniciar sesión. Hasta entonces no filtrar.

Tener en cuenta (hablarlo después): aviso al cobrar **sin cliente**. Hoy el contado puede ir sin ficha («Mostrador · Consumidor final») y eso es válido. Un modal de «¿estás seguro?» en cada nota frena el mostrador y el cajero lo salta. No implementarlo ahora. Cuando se hable: no bloquear el cobro de todos los días; si hay aviso, que sea suave o solo cuando sin cliente la venta queda mal (factura, seña, fiado: ahí ya es obligatorio, no pregunta). Opcional: chip visible, o preguntar solo si el total o el descuento son altos.

Tener en cuenta (falta análisis, no tocar): **compra flash / entrada express**. El servicio y el modal ya están, pero no se pulen ni se prueban hasta hablarlo. Hoy «Confirmar entrada» no marca `confirmo_costos` (el servicio rechaza). Tampoco está el alta de proveedor/contacto ni reasignar el ocasional. Ver `esstructuraybasecondiciones.md` punto 5 y futuro ítem 13.

## Fase 6. Seña, fiado y adelanto

Se completa `pago_aplicaciones` y los estados `reserva`, `credito` y `vencida`.

Reglas: la seña aparta `cantidad_reservada` y no saca la pieza; el plazo parte de 48 horas y al crearla puede ser menor o mayor; solo el supervisor cambia `vence_en` después; al vencer, el stock se libera, no hay kardex y la seña queda a favor; el fiado saca stock hoy y el cobro entra en la caja del día en que pagan; un pago puede cubrir varias ventas; un adelanto sin pieza no vuelve a sumar en la caja; el saldo no se guarda en el cliente, se calcula.

Pantalla: la misma venta Livewire gana estos modos. Un comando programado libera las señas vencidas.

## Fase 7. Devoluciones

Tablas: `notas_credito_devoluciones`, `nota_credito_detalles`.

Reglas: supervisor obligatorio; se devuelve la línea vendida, no un producto suelto, y no más unidades de las vendidas; el reingreso usa el costo congelado de esa línea; la plata sale de la caja de hoy; si la seña sigue vigente, se anula y se libera lo apartado; si el fiado se devuelve, deja de ser deuda y solo se reembolsa lo ya cobrado.

Pantalla: flujo Livewire de devolución, abierto desde la venta `VNT-`.

## Fase 8. Factura manual

Tablas: `talonarios`, `talonario_anulaciones`.

Reglas: solo si el comprobante es `factura_manual` hacen falta cliente, número y talonario; un talonario activo por sucursal; el número cae dentro del rango; anular esa factura exige la observación del papel y la hoja anulada. Quien solo usa nota de venta no ve esto.

Pantalla: resource de talonarios. El mostrador Livewire suma el tipo de comprobante.

## Fase 9. Ajustes, conteo, traslados y corrección de costo

Tablas: `ajustes_inventario`, `ajuste_detalles`, `traslados`, `traslado_detalles`, `correcciones_costo`.

Reglas: el ajuste cambia unidades, no el promedio, con motivo y supervisor; el conteo compara al cerrar e incluye las señas; no cierra si dejaría el stock bajo lo reservado; el traslado une salida y entrada y no se usa si el almacén es compartido; corregir un costo no edita la compra ni las ventas ya hechas, y el movimiento lleva cantidad cero.

Pantalla: resources Filament. El kardex sigue siendo de solo inserción.

## Fase 10. Caja chica

Tablas: `categorias_gastos`, `caja_chica_movimientos`.

Reglas: el cierre de caja se bloquea si hay un vale en `pendiente_comprobante`. El egreso y la devolución del sobrante quedan ligados a la sesión abierta.

Pantalla: las categorías de gasto sí son un resource Filament, porque es un catálogo. El vale no. En la caja abierta, un bloque Livewire registra el monto, el concepto, quién lo pidió y el comprobante, y deja ver los vales pendientes de esa sesión. Un resource genérico de Filament sería un formulario largo, fuera del turno, y el cajero no vería el candado del cierre. El historial de vales ya rendidos puede listarse en tabla, sin formulario de alta.

## Fase 11. Dólar de referencia

Tablas: `tipos_cambio`. Se usan `precio_venta_dolar` y `precio_mayor_dolar`.

Reglas: no se cobra en dólares; el tipo de cambio lo carga un supervisor y el anterior no se borra; la compra en dólares guarda el cambio de ese día y el promedio queda en la moneda de la tienda; al confirmar un cambio nuevo, un job en cola actualiza por lotes solo los productos con precio en dólares; reintentar el mismo dólar no duplica; el costo promedio no se mueve.

Pantalla: página Filament del tipo de cambio, visible solo si `modo_moneda` es `con_dolar`.

## Qué no entra en estas fases

Cobrar el mostrador con billetes de dólar. El dólar solo convierte. El cliente de otro país paga en la moneda de la tienda.
