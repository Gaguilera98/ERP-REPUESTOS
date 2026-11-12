# ERP Repuestos v1 - Documento Maestro Unificado

Estado operativo actualizado el 6 de noviembre de 2026 en [fase_6_estado_actual.md](fase_6_estado_actual.md). Las funciones planificadas se distinguen del alcance implementado en ese archivo.

Este documento unificado contiene la arquitectura completa, las reglas de negocio estrictas (con su respectivo "Por qué" y "Para qué") y el diseño relacional de la base de datos para el sistema multi-negocio **ERP Repuestos v1**, diseñado para operar con alta velocidad en mostrador, control estricto de inventario por Costo Promedio Ponderado (PPP), talonarios fiscales físicos, sesiones de caja con candados de auditoría, gestión de devoluciones inmediatas y el protocolo de **Entrada Express por Faltante / Producto Nuevo con Proveedor Ocasional y Creación Al Vuelo**.

---

## PARTE 1: REGLAS DE NEGOCIO ESTRICTAS

### 1. Diseño Técnico y Mapeo de Catálogo
* **Identificación Única (SKU vs Código de Barras):** 
  * *Por qué:* El SKU representa la designación técnica internacional del fabricante (ej: 6204-2RS) junto con sus variaciones. Es de control interno obligatorio y único por cada variante física dentro del negocio. Otro negocio puede usar el mismo código. El código de barras (EAN/UPC) es el número de la etiqueta para lectura rápida: dentro del negocio, si existe, también es único, para que el lector abra una sola variante.
  * *Para qué:* Garantizar que la búsqueda técnica en mostrador sea precisa sin depender de etiquetas impresas genéricas.
* **Búsqueda Técnica Avanzada por Atributos:** 
  * *Por qué:* Muchas veces el cliente no trae el código. Trae una medida, una rosca o un voltaje, y el rubro no es siempre un rodamiento.
  * *Para qué:* El mostrador filtra las variantes de esa categoría por los atributos que la categoría definió. La igualdad de varias medidas a la vez la resuelve el índice del JSON. La unidad no se escribe en la búsqueda: si el diámetro interno está en milímetros, `20` es `20 mm` en todo ese rubro.
* **Herencia de Atributos por Categoría y Variantes:** 
  * *Por qué:* Cada rubro tiene su ficha. Un rodamiento pide diámetros, un filtro pide rosca y altura, una lámpara pide voltaje y casquillo. Esos campos no pueden ser columnas fijas de la variante ni un texto libre.
  * *Para qué:* La categoría define cada atributo con su tipo, si es obligatorio y, cuando se mide, su unidad. Al crear la variante, el formulario arma esos campos y ningún otro. Los valores quedan en `atributos_json`. El SKU, el código de barras y los precios siguen siendo columnas de la variante, iguales para todos los rubros.
* **Ubicación Alfanumérica por Coordenadas Estrictas:** 
  * *Por qué:* Las bodegas de repuestos manejan miles de piezas pequeñas.
  * *Para qué:* Exigir una codificación estructurada (ej: `PASILLO-01-ESTANTE-04-ALTURA-02`) en el stock de cada almacén, para optimizar el tiempo de picking. La misma pieza puede estar en otro pasillo en otro depósito.

### 2. Inventario Físico y Kardex Financiero (PPP)
* **Cálculo Automático por Costo Promedio Ponderado (PPP):** 
  * *Por qué:* Sobrescribir manualmente el precio de compra o aplicar precios arbitrarios arruina los costos y falsifica la utilidad bruta.
  * *Para qué:* Cada entrada de mercancía (vía Órdenes de Compra o Entradas Express) calcula automáticamente el nuevo PPP combinando stock actual e inversión nueva, actualizando de forma inalterable el Kardex (`Movimientos_Inventario`) y congelando el costo al momento de vender (`precio_costo_snapshot`).
* **Confirmación del costo al guardar la compra:**
  * *Por qué:* Un 200 tipeado en vez de 20 sube el PPP del producto y la venta a 50 parece una pérdida.
  * *Para qué:* Antes de que la entrada toque el kardex, la pantalla muestra el costo por unidad de cada producto de esa compra y pide confirmación. Ejemplo: «Este rodamiento te costó 200 por unidad. ¿Confirmas?». Si no confirman, no se guarda. Si confirman un número mal tipeado, la corrección de costo sigue disponible para lo que quede en el estante.
* **Alerta si el precio de venta está por debajo del PPP:**
  * *Por qué:* El precio de venta lo pone el negocio. Si el promedio ya quedó más alto que ese precio, vender es vender a pérdida según el sistema.
  * *Para qué:* Al agregar el producto a la venta, o al aplicar el descuento, si `precio_costo` es mayor que el precio unitario que va a pagar el cliente, se muestra una alerta con los dos números. No bloquea la venta. El cajero puede seguir.
* **Cálculo de Disponibilidad de Stock:** 
  * *Por qué:* Las reservas de mercadería apartan stock sin descontarlo físicamente de inmediato.
  * *Para qué:* Calcular el stock real apto para la venta mediante la fórmula en tiempo real: $\text{Stock Disponible} = \text{cantidad\_actual} - \text{cantidad\_reservada}$.
* **Kardex Obligatorio e Inalterable:** 
  * *Por qué:* Ninguna pieza puede entrar o salir de la bodega sin dejar rastro contable y físico.
  * *Para qué:* Prohibir la alteración directa del stock sin generar un movimiento correlativo en el Kardex que registre el stock previo, el posterior y el responsable. La venta bloquea la fila de stock antes de descontar, para que dos cajeros no vendan la última unidad al mismo tiempo.
* **La cantidad vive en la unidad base del producto:**
  * *Por qué:* Un 10 en la compra no dice si son 10 carburadores, 10 kilos o 10 cajas. El estante, el kardex y el costo promedio tienen que hablar la misma unidad, si no el promedio mezcla cajas con piezas.
  * *Para qué:* Cada producto tiene `unidad_base_id`. El stock, el kardex y `precio_costo` están siempre en esa unidad. La unidad base es la más chica con la que se cuenta, se compra o se vende. Un tornillo es la pieza. Un aceite puede ser el mililitro. No se cambia la unidad base si ya hay movimientos.
  * *Para qué, en la compra:* La línea guarda la unidad tipeada, la cantidad tipeada, el factor congelado y `cantidad_base`. El stock recibe solo `cantidad_base`. Cambiar el factor mañana no reescribe la compra vieja. `costo_anotado` es el precio de la unidad tipeada. `costo_unitario` es ese precio dividido por el factor: el costo de una unidad base, y es el que entra al PPP. El subtotal es cantidad tipeada por `costo_anotado`.
  * *Para qué, en la venta (todavía sin tablas):* La línea va a guardar la unidad, la cantidad tipeada, el factor congelado y `cantidad_base`. El stock sale y la seña reserva `cantidad_base`. `precio_venta`, `precio_mayor` y `precio_costo` son por unidad base. La plata de la línea es `cantidad_base` por ese precio, menos el descuento. La alerta de pérdida compara el precio por unidad base contra `precio_costo`.
  * *Para qué, en la conversión:* Solo dentro de la misma familia. El kilo vale 1000 gramos. No se pasa de kilos a litros. Una caja de 100 tornillos es un factor de ese producto (`producto_unidades`), no una unidad global. Una caja sellada con su propio código de barras es otro producto. Las unidades de un dato del rubro (mm, V, presión) siguen aparte y no se mezclan con las de cantidad.
* **Ajuste de unidades y conteo físico:**
  * *Por qué:* Cambiar el número del estante sin motivo y sin supervisor hace desaparecer piezas y valor, y no queda por qué.
  * *Para qué:* Un ajuste corrige cuántas hay. El precio de la pieza no cambia. Lleva motivo (`merma`, `rotura`, `hallazgo`, `uso_interno` o `diferencia_conteo`) y un texto. Lo autoriza un supervisor. El kardex inserta una línea `ajuste` con el stock previo, el posterior, quién lo cargó y quién autorizó. Si el ajuste estaba mal, se carga otro en sentido contrario. El primero no se edita.
  * *Para qué, en el conteo:* Se cuenta el estante, incluidas las piezas de una seña, porque siguen ahí. Al cerrar se compara con el stock de ese momento. Si durante el conteo se vendió uno, ese uno ya salió por la venta. Cada diferencia es un ajuste `diferencia_conteo`. El cajero carga las cantidades. El supervisor cierra. Si la cantidad contada deja el stock por debajo de lo reservado, el cierre no pasa. La caja no se mueve.
* **Corrección de un costo ya cargado:**
  * *Por qué:* Si la compra se tipeó a 200 y el costo real era 20, el precio promedio queda mal. Borrar esa línea del kardex, o cambiar el costo de las ventas ya cerradas, falsifica el pasado.
  * *Para qué:* Un supervisor anota el costo real, ligado a la línea de la compra. Las ventas ya hechas conservan su `precio_costo_snapshot`. Lo que todavía está en el estante toma el costo nuevo de ahí en adelante. El kardex inserta una línea `correccion_costo` con cantidad cero: no entran ni salen piezas. La compra vieja no se edita. La caja no se mueve.

### 3. Ventas, Comprobantes y Control de Talonario Fiscal
* **Emisión Dual de Comprobantes (`nota_venta` vs `factura_manual`):** 
  * *Por qué:* En la práctica comercial, se requiere agilidad extrema en mostrador y cumplimiento fiscal ante Impuestos Nacionales (SIN) sin depender de integraciones electrónicas complejas desde el inicio.
  * *Para qué:* El sistema emite un ticket comercial rápido (`nota_venta`) con todo el detalle de los repuestos para entregar al cliente o grapar al papel. Si requiere factura formal, se registra como `factura_manual`, exigiendo el `cliente_id` (con razón social / nombres y un documento de identidad del catálogo, tipicamente NIT o CI) y el `numero_comprobante` físico del talonario, facilitando al contador un reporte limpio y libre de errores de tipeo.
  * *Pospuesto el 11 de noviembre de 2026:* la venta vigente es solo `nota_venta`. El talonario de papel se lleva afuera del sistema y la nota se adjunta a la libreta. `factura_manual` queda en el diseño para cuando un local necesite anotar el número de la hoja en el ERP.
* **Snapshot Fiscal Histórico:** 
  * *Por qué:* Las leyes tributarias o porcentajes impositivos pueden cambiar en el futuro.
  * *Para qué:* Congelar los valores de impuestos (`venta_impuestos`) como texto y número fijo durante la transacción para proteger las auditorías contables pasadas. El porcentaje vigente vive en `impuestos`. La venta copia nombre y porcentaje. Si la ley cambia, se edita el impuesto. Las ventas viejas no.
* **Precio al por menor, al por mayor y descuento:**
  * *Por qué:* El mostrador y el taller no pagan el mismo precio. Además el descuento a veces es un porcentaje y a veces es un monto en la moneda de la tienda. Hace falta en los dos precios.
  * *Para qué:* `precio_venta` es el precio al por menor. `precio_mayor` es el de mayorista. El cliente tiene `tipo_precio`: `menor` o `mayor`. Si no tiene marca, paga el menor. Si es mayorista y el producto no tiene `precio_mayor`, paga el menor.
  * *Para qué, el descuento de la venta:* En el mostrador el descuento que se ve es el del ticket, a la derecha, en Resumen y cobro. No está siempre visible: el cajero pulsa «Agregar descuento». El tipo es `porcentaje` o `importe`. Se calcula sobre el subtotal ya neto de las líneas. Queda en `ventas.tipo_descuento`, `ventas.valor_descuento` y se suma a `ventas.descuento`. No es un descuento de catálogo: es una excepción de esa nota.
  * *Para qué, el descuento de línea:* El detalle sigue pudiendo guardar `tipo_descuento`, `valor_descuento` y `descuento_aplicado`. En v1 el mostrador no lo muestra: no es el camino de todos los días. `ventas.descuento` es la plata total restada (líneas + ticket). Ningún descuento puede dejar el total en negativo.
  * *Para qué, el tope:* El cajero puede llegar hasta `descuento_max_porcentaje` (de partida 10) o hasta `descuento_max_importe` (de partida 0, o sea que un importe fijo lo autoriza el supervisor hasta que el negocio suba ese tope). Pasado el tope, en menor o en mayor, hace falta un supervisor. Eso vale para el descuento que pone el cajero, no para una promo cargada en el producto.
  * *Para qué, la clave (aún no en pantalla):* El backend ya exige `supervisor_descuento_id` si se pasa el tope. El mostrador todavía no pide contraseña. Hay dos autorizaciones distintas, y conviene las dos:
    * **Dentro del tope:** el cajero puede descontar, pero vuelve a ingresar **su** contraseña. Queda huella de que fue él. No hace falta otra persona.
    * **Pasado el tope:** un supervisor se acerca, pone **su** usuario y contraseña (rol Shield `supervisor` del mismo negocio) y queda `supervisor_descuento_id`. No se usa la sesión del cajero para “ser supervisor”.
    Hoy, si el descuento pasa el tope, la venta no cierra y pide autorización. Nadie tipea clave. Ver punto 25 y el ítem de futuro.
  * *Para qué, el precio de promo del producto (aún no):* Una campaña («este filtro está 80 esta semana») no la tipea el cajero. Se carga en la **gestión de productos**, en la variante. El mostrador la aplica solo. Ver nota en el punto 25 y el ítem de futuro.
* **La moneda es de la tienda, y el dólar solo convierte:**
  * *Por qué:* La caja, la factura y el costo promedio tienen que estar en la misma moneda. Una tienda en Bolivia cobra en bolivianos, una en Perú en soles y una en Brasil en reales. El cliente que entra de otro país no cambia esa moneda. El dólar sube y baja, y no todas las tiendas lo usan. No se cobra en dólares.
  * *Para qué:* En la configuración, `moneda` nace en `BOB` (Bs). También puede ser `PEN` (S/) o `BRL` (R$). `modo_moneda` nace en `solo_moneda`. Si el negocio importa, lo pasa a `con_dolar`. Ahí hay una pantalla para poner el tipo de cambio del día: cuántas unidades de la moneda de la tienda vale un dólar. En Bolivia puede ser 11. En Perú puede ser 3,70. Cada cambio queda guardado con fecha. El anterior no se borra. Lo carga un supervisor.
  * *Para qué, en la compra:* La línea puede anotarse en la moneda de la tienda o en dólares. Si es en dólares, guarda el monto en dólares, el tipo de cambio de ese momento y el resultado en la moneda de la tienda. Ese resultado es el que entra al promedio. Si mañana el dólar sube, esa compra no se mueve.
  * *Para qué, en el precio:* `precio_venta` y `precio_mayor` siguen en la moneda de la tienda. Si la pieza tiene `precio_venta_dolar` o `precio_mayor_dolar`, al guardar un tipo de cambio nuevo el sistema calcula el precio en la moneda de la tienda de todas esas piezas, de una vez. Muestra cuántas cambian y pide una sola confirmación. No lo cambia solo y no pide confirmar producto por producto. Las que no tienen precio en dólares no se tocan. La venta ya cerrada conserva el precio que se cobró. El cliente no tiene moneda propia.
* **Contado, seña, fiado y adelanto son el mismo dinero:**
  * *Por qué:* El camino de todos los días es cobrar la venta completa en el momento. También existe dejar una seña para apartar, llevarse la mercadería y pagar después, y dejar plata a cuenta sin una pieza concreta. Armar una tabla para cada caso duplica la caja y el saldo deja de cuadrar entre negocios.
  * *Para qué:* `pagos` registra solo la plata que entra o sale de la caja de ese turno. `pago_aplicaciones` dice a qué venta, a qué nota de crédito o a qué saldo a favor se aplica. El saldo no se guarda en el cliente: se calcula. El comprobante nace con la venta. Cada abono no es otra venta.
  * *Para qué, en el mostrador:*
    * **Contado.** `condicion = 'contado'` y `estado = 'completada'`. El pago cubre el total, entra a la caja de hoy y el stock sale. El cliente puede no existir.
    * **Seña.** `estado = 'reserva'`, el cliente es obligatorio y la venta guarda `vence_en`. El plazo de partida del negocio es 48 horas. Al crearla se puede poner menos o más. Después, quien tenga View:Venta y Ampliar:Reserva puede ampliar `vence_en` mientras la reserva siga vigente, dejando motivo e historial. El pago de hoy entra a la caja de hoy y se aplica a esa venta. El stock no sale: sube `cantidad_reservada`. Lo que falta queda por cobrar sobre esa venta. Si el cliente vuelve a tiempo y paga el resto, pasa a `completada` y el stock sale. Si `vence_en` se cumple y no volvió, la venta pasa a `vencida`, `cantidad_reservada` baja y la pieza vuelve a estar disponible. No hay kardex, porque la pieza nunca salió. La seña queda como saldo a favor de ese cliente. La caja del día en que la dejó no se toca. Si quiere la plata de vuelta, es un reembolso en la caja del día en que la pide.
    * **Fiado.** `condicion = 'credito'`, `estado = 'completada'` y el cliente es obligatorio. El stock sale hoy. La caja de hoy no se mueve si no dejó plata. Cuando pague, ese cobro entra a la caja del día en que paga.
    * **Un pago, varias deudas.** Un solo `pagos` y varias filas de `pago_aplicaciones`, una por venta del mismo cliente.
    * **Adelanto sin pieza.** Un cobro con cliente y una aplicación sin venta. Queda saldo a favor. Cuando compra, una aplicación nueva apunta a esa fila y a la venta. La caja no vuelve a sumar esa plata.
  * *Para qué, al devolver:* Un reembolso es un `pagos` con `sentido = 'reembolso'` en la caja de hoy, aplicado a la nota de crédito. Si se devuelve una seña que sigue en reserva, además se libera `cantidad_reservada` y la venta pasa a `anulada`. Si el fiado se devuelve, la venta deja de contar como por cobrar. Se reembolsa solo la plata ya cobrada. Lo que nunca se pagó no sale de la caja.

### 4. Protocolo de Anulaciones y Cambios Inmediatos en Mostrador
* **Restricción de Edición en Caliente:** 
  * *Por qué:* Permitir modificar una venta ya cerrada o pagada abre la puerta a fraudes y descuadres de caja.
  * *Para qué:* Ninguna venta finalizada se edita directamente; toda corrección posterior exige un flujo controlado.
* **Anulación con Autorización de Supervisor (`supervisor_id`):** 
  * *Por qué:* Los cajeros u operadores rasos no pueden anular operaciones a libre albedrío.
  * *Para qué:* Toda anulación exige credenciales de supervisor, revierte atómicamente el inventario al Kardex y, si involucra una `factura_manual`, exige la anulación física del papel en el talonario con observación obligatoria para el contador.
* **Devoluciones Parciales y Cambios Cruzados (Mismo Día / Turno Abierto):** 
  * *Por qué:* El cliente típico se va al auto, se da cuenta de que la medida era otra y regresa a los 5 minutos; anular todo el día es inviable.
  * *Para qué:* Permitir mediante una *Nota de Cambio / Devolución* autorizada por supervisor reingresar la pieza al Kardex y compensar el saldo de manera cruzada sin destruir la venta original ni la caja del turno.

### 5. Protocolo de Venta con Faltantes de Stock (Entrada Express de Emergencia)
* **Gestión de Faltantes o Productos Nuevos sin Stock:**
  * *Por qué:* En el mostrador de repuestos ocurren dos realidades: el producto existe pero está en stock 0, o el producto ni siquiera está creado en el sistema, pero se consigue al instante con un colega de la cuadra.
  * *Para qué:* Permitir al operador registrar un alta exprés del producto (si no existe) y activar una **Entrada Express** que genere al vuelo una micro-compra de emergencia sin romper el Kardex ni permitir stock negativo.
* **Proveedor Ocasional y Creación Al Vuelo con Multicontactos:**
  * *Por qué:* En el apuro de una venta de emergencia, el operador no puede perder tiempo buscando o creando un proveedor completo.
  * *Para qué:* El sistema asigna por defecto el proveedor comodín de ese negocio (**"Compra Ocasional / Proveedor Local"**, uno por negocio), y la ficha mínima no pide proveedor ni contacto. El proveedor real se crea en su catálogo y se asigna posteriormente a esa compra desde Compras, sin repetir el ingreso de stock.
* **Entrada express — decisiones de cierre fase 5:** el check «Confirmo estos costos por unidad» es obligatorio antes de pulsar «Confirmar entrada», igual que al recibir compras. La entrada registra compra completada, stock, kardex y PPP, sin egreso de caja (fase 10). Producto nuevo: nombre, cantidad, costo y precio de venta; SKU opcional/autogenerado, categoría General, marca vacía y unidad pza. La compra usa el ocasional. Desde la vista de Compras, quien tiene permiso de actualizar puede asignar un proveedor real del negocio, sin repetir recepción ni cambiar costos. El alta del proveedor se hace en Proveedores; no se requiere en el mostrador. Correcciones de costo: fase 9.

### 6. Control Financiero, Cajas y Caja Chica
* **Arqueo y Cálculo Automático de Desfases:** 
  * *Por qué:* El cierre de caja debe ser transparente y auditable matemáticamente.
  * *Para qué:* El sistema compara el efectivo físico contado con el teórico registrado, obligando al cajero a justificar por escrito cualquier diferencia.
* **Control de Rendiciones y Vales de Caja Chica:** 
  * *Por qué:* Las salidas de efectivo para compras menores no pueden quedar volando sin respaldo.
  * *Para qué:* El sistema bloquea preventivamente el cierre de caja si existen movimientos en `estado_rendicion = 'pendiente_comprobante'`.

---

## CORRECCIONES

Estas correcciones no cambian las reglas de mostrador, PPP, talonario ni caja. Fijan dónde vive cada dato cuando muchos negocios comparten el pool, para que un SKU, un NIT o una pieza no se mezclen entre negocios.

### 1. El tenant es el negocio, no la sucursal
* *Por qué:* Una ferretería y un taller son dos clientes del sistema. Cada uno puede tener varias sucursales. Si una sucursal fuera otro tenant, el kardex y la caja se partirían y el costo promedio dejaría de cuadrar.
* *Para qué:* `tenants` y `domains` viven en la base central. El subdominio (`norte`) se guarda en `domains` y abre `norte.localhost`. Sucursales, almacenes, stock y ventas viven en el pool, todos con el mismo `tenant_id` del negocio.

### 2. `tenant_id` en toda tabla del pool
* *Por qué:* En una base compartida, un único puesto solo sobre `sku` o `nit` impide que el segundo negocio use el mismo código. Una foreign key solo por `id` deja guardar una venta del negocio A con el producto del negocio B.
* *Para qué:* Toda tabla de la Parte 2 lleva `tenant_id` (string, el id del tenant, sin foreign key a la central porque es otra base). El único y el índice empiezan por `tenant_id`. Cada tabla padre tiene único `(tenant_id, id)`. Cada foreign key del pool es compuesta: `(tenant_id, producto_variante_id)` apunta a `(tenant_id, id)`. `user_id` y `supervisor_id` apuntan a `users` del pool, nunca al admin de la plataforma.
* *Para qué, al borrar:* Cada tabla nueva entra en la lista de limpieza del pool, hijas antes que padres. Si falta, al borrar el negocio quedan ventas y kardex ajenos en la base compartida.

### 3. Almacén compartido: solo la pivote
* *Por qué:* Hay sucursales con depósito propio y otras que venden del mismo montón. `sucursal_id` en el almacén dice que pertenece a una sola sucursal. La pivote dice que lo usan varias. Las dos juntas se contradicen y el kardex no sabe de quién era la pieza.
* *Para qué:* `almacenes` no tiene `sucursal_id`. `sucursal_almacenes` es el único vínculo. Una fila significa «es su almacén»; dos filas significan «comparten el mismo saldo». `es_principal` vive en la pivote: un depósito compartido puede ser el principal de una sucursal y el de respaldo de otra. La venta guarda `sucursal_id` (quién cobró) y `almacen_id` (de qué montón salió).
* *Para qué, si el galpón es el mismo pero el saldo no:* Eso no es un almacén compartido. Son dos almacenes y el pase de mercadería es un traslado de kardex (`traslado_salida` y `traslado_entrada`).

### 4. El mismo código puede existir en otro negocio
* *Por qué:* `6204-2RS`, un NIT y el número de un talonario se repiten en la vida real entre negocios, sucursales y tipos de comprobante.
* *Para qué:* Los únicos quedan así:
  * Variante: `(tenant_id, sku)`.
  * Documento de cliente: `(tenant_id, tipo_documento_identidad_id, identidad)`. La identidad sale de los atributos marcados «identifica» (ej. número + complemento del CI). Un mismo tipo no se repite en el mismo cliente.
  * Proveedor: `(tenant_id, nit)`.
  * Comprobante de venta: `(tenant_id, sucursal_id, tipo_comprobante, numero_comprobante)`.
  * Stock: `(tenant_id, producto_variante_id, almacen_id)`. Una variante tiene una sola fila de stock por almacén.

### 5. La ubicación es del almacén, no de la variante
* *Por qué:* La misma pieza puede estar en `PASILLO-01` en un depósito y en `ESTANTE-04` en otro.
* *Para qué:* `ubicacion` va en `inventario_stocks`, junto al almacén. No va en `productos_variantes`.

### 6. El kardex no apunta a un id suelto
* *Por qué:* `referencia_id` solo, sin tipo, puede caer en una venta, en una compra o en una fila de otro negocio.
* *Para qué:* El movimiento guarda `referencia_tipo` (`venta`, `orden_compra`, `nota_credito`, `traslado`, `ajuste` o `correccion_costo`) y `tenant_id`. También guarda `stock_previo`, `stock_posterior` y `user_id` del pool. La fila se inserta y no se edita. La aplicación comprueba que la fila referenciada sea del mismo tenant. Postgres no puede imponer sola esa relación polimórfica.

### 7. El proveedor ocasional es de cada negocio
* *Por qué:* Si hubiera un solo proveedor comodín para todo el pool, las compras flash de todos los negocios compartirían esa fila.
* *Para qué:* Al crear el negocio se inserta un proveedor con `es_ocasional = true` y su `tenant_id`. Solo puede haber uno por negocio. Después se puede reasignar la compra al proveedor real.

### 8. Categorías y trabajadores faltaban en el esquema
* *Por qué:* `categoria_id` y `trabajadores` ya se usaban en las foreign keys y no tenían tabla. Cada negocio clasifica y emplea a los suyos.
* *Para qué:* `categorias` y `trabajadores` viven en el pool, con `tenant_id`. La plantilla JSON de atributos cuelga de la categoría. El trabajador puede tener `user_id` opcional hacia el usuario del pool, y ese usuario tiene que ser del mismo `tenant_id`.

### 9. Lo que no se mueve de las reglas de negocio
* *Para qué:* El PPP se recalcula en cada entrada y se congela en `precio_costo_snapshot` al vender. Los impuestos de la venta se guardan como snapshot. La venta cerrada no se edita: se anula o se devuelve con supervisor. La caja no cierra si hay un vale pendiente de comprobante. Los archivos de comprobante siguen siendo una ruta; el disco ya separa los archivos por tenant.

### 10. El código de barras no es el SKU
* *Por qué:* La regla ya separa el código interno del número de la etiqueta. Sin columna, el lector de mostrador no tiene dónde caer.
* *Para qué:* `productos_variantes.codigo_barras` es nullable y único `(tenant_id, codigo_barras)`. El SKU sigue siendo la identidad de la variante. El código de barras es la etiqueta que lee el mostrador, y dentro del negocio no puede apuntar a dos variantes: si se repite, el lector no sabe cuál vender. El vacío puede repetirse, así que una pieza sin etiqueta no choca con otra. Otro negocio sí puede tener el mismo código, porque el único incluye `tenant_id`.

### 11. El desfase de caja tiene que quedar escrito
* *Por qué:* La regla obliga a justificar por escrito cualquier diferencia, y `diferencia` solo guarda el número.
* *Para qué:* `cajas_sesiones` lleva `justificacion_diferencia`, `fecha_apertura` y `fecha_cierre`. Si la sesión está cerrada, `fecha_cierre` es obligatoria. Si `diferencia` no es cero, el texto de la justificación es obligatorio. Un índice parcial permite una sola sesión `abierta` por `(tenant_id, sucursal_id, user_id)`.

### 12. La devolución también mueve el dinero del turno
* *Por qué:* El cambio del mismo día compensa el saldo con la caja abierta. Si el reembolso no tiene sesión, el arqueo no lo ve.
* *Para qué:* `notas_credito_devoluciones.caja_sesion_id` apunta al turno de esa sucursal. El dinero que sale es un `pagos` con `sentido = 'reembolso'`. La aplicación de ese pago apunta a la nota, no a la venta. Un cobro es `sentido = 'cobro'` y sus aplicaciones apuntan a ventas o a saldo a favor. El monto sigue siendo positivo. El efectivo teórico del turno es la suma de cobros en efectivo menos reembolsos en efectivo menos los egresos de caja chica. Aplicar después un adelanto no crea otro pago y no cambia la caja del turno viejo.

### 13. Se devuelve una línea vendida, no un producto suelto
* *Por qué:* Sin la línea original, la base no sabe cuántas unidades salieron ni con qué costo congelado deben reingresar.
* *Para qué:* `nota_credito_detalles.venta_detalle_id` es obligatorio cuando `tipo_accion = 'devuelve_stock'`. En un cambio, la línea que sale de nuevo puede no tener venta de origen. `precio_costo_snapshot` de la devolución se copia del de esa línea, para que el reingreso al kardex no recalcule el PPP como si fuera una compra nueva. La suma devuelta de una línea no puede superar `venta_detalles.cantidad`. Eso lo cierra la aplicación dentro de la transacción, con la línea bloqueada, porque cruza varias filas y Postgres no lo impone con un CHECK.

### 14. El traslado entre almacenes es un documento
* *Por qué:* `traslado_salida` y `traslado_entrada` existen en el kardex, pero nada los ata. Sin eso, una salida puede quedar sin su entrada.
* *Para qué:* `traslados` guarda origen, destino, usuario y estado. El detalle guarda la variante y la cantidad. Cada línea completada genera los dos movimientos, ambos con `referencia_tipo = 'traslado'` y el mismo `referencia_id`. Origen y destino no pueden ser el mismo almacén. Esto vale cuando hay dos saldos distintos. Si el saldo es uno solo compartido por sucursales, no hay traslado: alcanza la pivote.

### 15. Los roles del negocio son plantillas; autoriza el permiso
* *Por qué:* Anular, devolver y cerrar un desfase exigen alguien autorizado. El usuario del pool no lleva una columna de rol. Hardcodear `if ($rol === 'supervisor')` impide crear roles nuevos en Shield (solo stock, solo compras, etc.) sin tocar código.
* *Para qué (hoy):* Filament Shield guarda los roles del negocio en el pool. El equipo es el `tenant_id`. Cada negocio nace con `cajero` y `supervisor`. El supervisor autoriza anulaciones, devoluciones y desfases. El cajero puede cobrar y no administra roles. Quien dueña el negocio entra hoy como supervisor. `supervisor_id` apunta a un usuario del mismo `tenant_id`. La foreign key garantiza ese tenant. La plataforma guarda sus roles en la base central, con el equipo `platform` y el guard `web`. El primer rol de plataforma es `admin`. Esos roles no se mezclan con los del negocio. No se usa el `super_admin` de Shield (`enabled = false`): el techo del negocio es un rol con todos los permisos, no un gate mágico.
* *Para qué (dirección acordada):* Autorizar por **permiso**, no por nombre de rol. Las plantillas sembradas son solo atajos:
  * **`encargado`** (matriz / dueño operativo): ve todo el negocio, crea usuarios y roles, crea sucursales y configura el sistema. Por convención, la creación de usuarios para todo el negocio se hace desde matriz y se delega.
  * **`supervisor`** (sucursal u operativo): ventas, stock, cajas, compras del día a día. **Sin** administración del sistema (usuarios, roles, alta de sucursales).
  * **`cajero`** / vendedor: cobrar y su caja. Sin administrar roles.
  El negocio típico tiene una **matriz** y otras sucursales. La encargada de matriz rinde cuentas de las demás (cierres, stock) y ve **todas** las sucursales. No hay roles por sucursal: un solo catálogo de roles del tenant. La sucursal del usuario es una **asignación aparte** (pivote), no otro set de roles. Se puede crear en Shield un rol custom (ej. solo stock) si tiene los permisos correctos, sin cambiar código.
* *Nota pendiente (sucursales del usuario + switch):* Hoy el usuario no tiene sucursales asignadas. El mostrador lista todas y, si no hay caja, toma la primera activa. Al crear el usuario hay que asignarle una o más sucursales (pivote `user_sucursales`, no una sola columna). Al iniciar sesión (o con un **switch en el menú**), elige **en qué sucursal trabaja** y solo ve las suyas. Ese contexto filtra operación: ventas, caja, stock operativo, talonario, listados, Cajas del día, etc. Si tiene varias, cambia de sucursal con el switch; si tiene una, entra directo. **No** filtra por ese switch: **usuarios** y **roles** (son del tenant, centrales). Quien tenga permiso de crear usuarios/roles los ve enteros sin cambiar de sucursal. No hace falta crear roles por sucursal. Ver futuro ítems 11 y 14.

### 16. Tres candados que pone la base
* *Por qué:* Stock negativo, factura manual incompleta y venta desde un almacén que la sucursal no tiene son fallos que el código puede olvidar en un camino.
* *Para qué:*
  * `inventario_stocks`: `cantidad_actual >= 0`, `cantidad_reservada >= 0` y `cantidad_reservada <= cantidad_actual`.
  * `ventas`: si `tipo_comprobante = 'factura_manual'`, entonces `cliente_id`, `numero_comprobante` y `talonario_id` no son nulos.
  * `ventas (tenant_id, sucursal_id, almacen_id)` apunta al único de `sucursal_almacenes`. Una sucursal no puede vender de un almacén que no tiene asignado.

### 17. El PPP no se redondea a dos decimales en cada entrada
* *Por qué:* Con `decimal(10,2)`, el promedio se recorta en cada compra y la utilidad se desfasa de a poco.
* *Para qué:* `precio_costo`, `costo_unitario`, `costo_promedio_resultante` y `precio_costo_snapshot` son `decimal(14,4)`. Precios de venta, impuestos y totales de dinero se quedan en `decimal(10,2)`.

### 18. Los atributos se definen en tablas y los valores viven en JSON
* *Por qué:* Poner `diametro_interno_mm`, `diametro_externo_mm` y `ancho_mm` como columnas fijas en `productos_variantes` lo ata a un solo rubro (rodamientos). Una tienda de filtros, pastillas o correas no necesita esas columnas. Tampoco existe un conjunto finito de atributos que sirva para todos los negocios.
* *Por qué, la definición:* Un JSON de claves con valor vacío no dice si el campo es un número, un texto o una lista, ni si es obligatorio, ni en qué unidad está. El mostrador termina guardando `"20"`, `20` y `"20 mm"` como si fueran lo mismo, y el filtro del diámetro no encuentra la pieza. Meter la unidad en el nombre (`diametro_interno_mm`) parte en dos un mismo dato.
* *Para qué, la definición:* `unidades_medida` y `categoria_atributos` son tablas del pool. `categorias` no tiene `plantilla_atributos`. Cada atributo tiene clave estable, etiqueta, tipo (`decimal`, `texto` o `lista`), si es obligatorio y el orden en la ficha. `unidad_medida_id` solo se completa en un decimal que se mide. En un texto, en una lista y en un decimal que no se mide (cantidad de dientes) queda nulo. No se crea una unidad «sin unidad». La clave no lleva la unidad: `diametro_interno`, no `diametro_interno_mm`. Una lista guarda sus opciones (`2RS`, `ZZ`, `ABIERTO`). La variante no puede agregar una clave que la categoría no tenga.
* *Para qué, la unidad:* `unidades_medida` guarda nombre, símbolo y magnitud (`longitud`, `electrico` o `presion`). Ejemplos: Milímetro/`mm`/longitud, Pulgada/`in`/longitud, Voltio/`V`/electrico. Cada negocio tiene las suyas. Al elegir la unidad, la pantalla agrupa por magnitud, así un diámetro solo ofrece unidades de longitud. Si el atributo ya tiene unidad, otra unidad nueva tiene que ser de la misma magnitud: un `20` no pasa de milímetros a voltios.
* *Para qué, el valor:* `productos_variantes.atributos_json` sigue siendo jsonb. Laravel lo trata con cast `array`. Guarda solo valores, con la clave del atributo. Un decimal se guarda como número JSON (`20`). Un texto o una opción de lista se guarda como texto (`"3/4-16 UNF"`, `"2RS"`). `3/4-16 UNF` es el código de la rosca, no una unidad: la unidad sigue vacía en ese atributo. La unidad no se copia en el JSON. La pantalla la lee del atributo y muestra `20 mm`. Al guardar, la aplicación exige los obligatorios y rechaza un texto en un decimal.
* *Para qué, la búsqueda:* El mostrador filtra dentro del negocio y de la categoría. La igualdad de varias medidas usa el índice GIN: `atributos_json @> '{"diametro_interno": 20, "diametro_externo": 47, "ancho": 14}'`. El mismo mecanismo vale para cualquier rubro: un filtro de aceite se busca por `rosca` y `altura`, una lámpara por `voltaje` y `casquillo`. Cambian las claves de esa categoría. Un rango (de 19.5 a 20.5) se compara como número después de acotar por negocio y categoría. Ese rango no lo resuelve el GIN. En SQLite (tests) el campo queda como json sin GIN.

### 27. La marca es una entidad del negocio
* *Por qué:* Un texto libre en la ficha guarda "Toyota" y "Toyotaa" como si fueran dos marcas. Una lista por categoría repite los mismos nombres en cada rubro.
* *Para qué:* `marcas` guarda solo el nombre, único por negocio sin distinguir mayúsculas ni espacios de más. El producto maestro apunta con `marca_id`. Todas sus variantes heredan esa marca. SKF y NSK del mismo rodamiento son dos productos. Para qué auto sirve la pieza no va en esta tabla. La marca no es un `categoria_atributos`.

### 19. El talonario físico tiene rango y hojas anuladas

Pospuesto el 11 de noviembre de 2026 (fase 8). El diseño se conserva. Hoy la venta es una nota de venta y la libreta de papel se controla afuera del sistema.
* *Por qué:* El único del número evita repetirlo, pero no sabe qué talonario está en uso ni qué hoja se anuló en papel. La regla de anulación de `factura_manual` exige esa observación para el contador.
* *Para qué:* `talonarios` pertenece a una sucursal y a `factura_manual`. Guarda `numero_desde`, `numero_hasta` y estado. Solo puede haber uno `activo` por `(tenant_id, sucursal_id, tipo_comprobante)`. Los rangos de una sucursal no se solapan: lo comprueba la aplicación al crear el talonario. La venta con factura manual apunta a ese talonario y su número tiene que caer dentro del rango. `talonario_anulaciones` registra cada hoja muerta en papel, con motivo, usuario y supervisor. Si la anulación nace de una nota de crédito, también guarda `nota_credito_id` y `notas_credito_devoluciones.observacion_anulacion_fisica` es obligatoria.

### 20. La seña, el fiado y el adelanto no tienen tabla propia
* *Por qué:* `pagos` podía colgar de una sola venta. Con eso no entra un abono que cubre tres deudas, ni una seña que aparta stock, ni plata a cuenta sin pieza. Guardar el saldo en el cliente se desfasa en cuanto hay dos cobros.
* *Para qué:* El pago es el movimiento de caja. `pago_aplicaciones` reparte ese monto. La suma de las aplicaciones originales de un pago es igual al monto del pago. Usar después un saldo a favor inserta otra aplicación que apunta a la primera y a la venta: no nace un pago nuevo. El por cobrar de una venta es `total` menos lo aplicado con cobros aprobados. El saldo a favor de un cliente es lo no aplicado menos lo que ya se usó. Una seña o un fiado exigen `cliente_id`. El contado de mostrador puede seguir sin cliente, y solo se completa si lo aplicado cubre el total. En el fiado el stock sale aunque quede saldo. En la reserva el stock queda en `cantidad_reservada` hasta que se entrega, se anula o vence.

### 21. La seña vence y suelta el stock
* *Por qué:* Sin un plazo, `cantidad_reservada` deja la pieza invisible para el resto de los clientes aunque siga en el estante. El plazo no puede ser el mismo para todos los negocios ni para todas las señas.
* *Para qué:* `negocio_configuracion.horas_reserva` nace en 48. Cada negocio puede cambiar ese valor de partida. Al crear la reserva, el cajero puede poner menos horas o más, y eso se guarda en `ventas.vence_en`. Después de creada, quien tenga View:Venta y Ampliar:Reserva puede ampliar `vence_en` mientras siga vigente, con motivo e historial. Al cumplirse el plazo, la venta pasa a `vencida`, baja `cantidad_reservada` y no se escribe kardex. La aplicación de esa seña se convierte en saldo a favor con una fila nueva de `pago_aplicaciones` (`venta_id` nulo, `aplicacion_origen_id` apuntando a la aplicación de la reserva, el mismo `pago_id`). No nace un pago nuevo y la caja vieja no se mueve.

### 22. El ajuste cambia unidades, no el precio
* *Por qué:* El tipo `ajuste` del kardex no tenía documento, motivo ni supervisor. Alcanzaba con escribir otro número.
* *Para qué:* `ajustes_inventario` es el documento y `ajuste_detalles` son las piezas. Al cerrarlo, cada diferencia inserta un movimiento `ajuste` con `referencia_tipo = 'ajuste'`. `costo_unitario` y `costo_promedio_resultante` quedan en el PPP que la pieza ya tenía. `precio_costo` de la variante no se toca. `supervisor_id` es obligatorio al cerrar. Una fila cerrada no se edita.

### 23. Un costo mal tipeado se corrige hacia adelante
* *Por qué:* La compra y las ventas ya cerradas no se reescriben. El promedio mal cargado tampoco puede quedar para siempre en lo que sigue en el estante.
* *Para qué:* `correcciones_costo` guarda el costo anterior, el nuevo, la línea de compra, el usuario y el supervisor. Actualiza `precio_costo` de la variante. Inserta un movimiento `correccion_costo` con cantidad cero y `referencia_tipo = 'correccion_costo'`. `stock_previo` y `stock_posterior` quedan iguales. Los `precio_costo_snapshot` de las ventas ya hechas no se tocan.

### 24. El costo se confirma al guardar, y la venta avisa si queda por debajo
* *Por qué:* La corrección hacia adelante no alcanza si el 200 entra sin que nadie lo vea, y tampoco si el mostrador vende a 50 sin notar que el promedio ya está en 200.
* *Para qué:* No hay tablas nuevas. Al guardar la compra, la pantalla obliga a confirmar el costo por unidad antes de escribir el kardex. Al vender, si el PPP es mayor que el precio unitario que va a pagar el cliente, ya con descuento, hay una alerta con ambos números y la venta puede continuar.

### 25. Menor, mayor y dos tipos de descuento
* *Por qué:* Un solo `precio_venta` y un descuento solo en dinero no distinguen al taller del mostrador, ni un 10 % de un descuento de un monto fijo.
* *Para qué:* El precio usado queda copiado en `precio_unitario` de la línea. El descuento de línea guarda `tipo_descuento` y `valor_descuento`, y `descuento_aplicado` es la plata que se restó. El descuento del ticket guarda lo mismo en `ventas` (`tipo_descuento`, `valor_descuento`, `supervisor_descuento_id`) y se aplica después, sobre el subtotal neto. Si el valor pasa el tope del negocio, `supervisor_descuento_id` es obligatorio. En v1 el cliente lleva `tipo_precio` `menor` o `mayor`: es la tarifa de partida. El producto guarda `precio_venta` (menor) y `precio_mayor`. Si el cliente es mayorista y la pieza no tiene mayor, paga el menor.
* *Nota pendiente (autorizar descuento con contraseña):* El campo `supervisor_descuento_id` ya existe en `ventas` y en `venta_detalles`. No hay modal. Hasta el tope, si se quiere huella, el cajero reingresa su propia contraseña (no cambia de usuario). Pasado el tope, un supervisor del mismo `tenant_id` ingresa las suyas; la app comprueba el rol Shield y guarda ese `users.id`. No alcanza con elegir un supervisor en un select: tiene que ser la clave. El mismo patrón sirve después para anular y devolver (el documento ya pide credenciales de supervisor ahí). No se implementa en el mostrador de la fase 5; queda anotado para no olvidarlo.
* *Nota a futuro (listas de precios estilo Odoo):* Hoy bastan dos tarifas fijas. Si el negocio necesita más (VIP, temporada, mínimo de cantidad, % por categoría, moneda por lista), no alcanza con el enum. Habrá que modelar **listas de precios** configurables por tenant (nombre + reglas) y que el cliente apunte a una lista, como el campo *Pricelist* de Odoo: al armar la venta se trae esa lista y se puede cambiar en el pedido puntual. Migración típica: `tipo_precio` deja de ser la fuente de verdad y pasa a ser un atajo o se reemplaza por `lista_precio_id`. Hasta entonces no inventar N columnas de precio en el producto ni en el cliente.
* *Nota a futuro (precio de promo en la gestión de productos):* Algunas tiendas bajan piezas concretas por campaña. Eso no es el descuento del cajero ni otra tarifa menor/mayor. Se carga en la ficha de la **variante** (recurso de productos), no en el mostrador. El cajero no lo tipea: al agregar la pieza, si hoy cae en la vigencia, el mostrador usa esa promo. No pide supervisor: la autorizó quien la cargó en el producto. Un `precio_promo` suelto no alcanza: sin `promo_desde` y `promo_hasta` se olvida y el precio queda barato. Hay que decidir si el mayorista también la paga o sigue en `precio_mayor`. Si después hay que hacer «todos los filtros 15 %», no se edita SKU por SKU: eso es una campaña o una lista, no más columnas. Mientras tanto no se agrega `precio_promo` a la variante. El descuento del ticket, si lo ponen, se calcula sobre el precio ya promocionado.

### 26. La moneda es de la tienda. El dólar solo convierte y no se cobra
* *Por qué:* Sin un lugar donde poner el tipo de cambio, la tienda que importa no puede convertir. Si ese número se pisa, las compras viejas cambian de costo. Si cada cliente trajera su moneda, la caja dejaría de cuadrar.
* *Para qué:* `negocio_configuracion.moneda` es `BOB`, `PEN` o `BRL`. `tipos_cambio.valor` dice cuántas unidades de esa moneda vale un dólar. Solo uno está vigente por negocio. La compra en dólares copia `tipo_cambio_valor` en la línea. `costo_unitario` queda en la moneda de la tienda y alimenta el PPP. Al confirmar el cambio nuevo, la pantalla no recorre los productos: encola un trabajo que actualiza `precio_venta` y `precio_mayor` por lotes, solo en los que tienen precio en dólares. Si un lote se reintenta con el mismo dólar, el precio queda igual. El costo promedio no se toca. La caja y el cliente siguen en la moneda de la tienda. No se cobra en dólares.
* *Nota pendiente:* Evaluar migrar `negocio_configuracion.moneda` de `enum('BOB', 'PEN', 'BRL')` a `varchar(3)` (código ISO 4217: BOB, PEN, BRL, COP, ARS, etc.) para que el SaaS no quede restringido a tres países y admita clientes de cualquier región, delegando la lista/validación de monedas permitidas a la capa de aplicación/formulario Filament.

### Estado de la implementación y decisiones vigentes

Actualizado el 11 de noviembre de 2026. La fase 6 queda cerrada con el alcance acordado, tras la ejecución completa compartida por el usuario: 163 pruebas aprobadas y 1.419 comprobaciones en 337,40 segundos. La fase 7 queda cerrada el 11 de noviembre de 2026 con el alcance comercial acordado: política, copia en la venta, devolución y cambio de piezas en buen estado, y exclusiones por categoría y producto. Los reclamos por falla y las piezas dañadas siguen en el ítem 15. Las funciones, límites y mejoras pendientes de la fase 6 se mantienen en [fase_6_estado_actual.md](fase_6_estado_actual.md). Las secciones de diseño posteriores describen también funciones planificadas; consultar ese estado antes de asumir que están implementadas.

* Las reservas actuales reciben una seña al crearse y el saldo completo al entregar. Se requiere cliente, se aparta stock disponible y se guarda un vencimiento cuyo valor inicial viene de la configuración del negocio. La entrega cobra el saldo en efectivo en la caja de hoy y descuenta stock. El vencimiento libera las unidades y registra la seña a favor del cliente. La aplicación de ese saldo a una compra se realiza desde Mostrador.
* Desde el 7 de noviembre de 2026 el saldo a favor disponible puede aplicarse a una compra en Mostrador. Se consume mediante una aplicación vinculada al pago original y no genera otro cobro. Las señas vencidas pueden reembolsarse según la condición guardada en la reserva, con Reembolsar:Sena, motivo y caja actual. La configuración permite_reembolso_senas se copia al crear cada reserva; valor inicial habilitado para reservas nuevas y anteriores. Retener definitivamente la seña sigue pendiente.
* El crédito permite pagos parciales en efectivo desde Mis ventas. Cada cobro se registra en la caja donde se realiza.
* Una reserva vigente puede cancelarse desde su detalle con View:Venta y Update:Venta, motivo obligatorio, usuario y fecha. Conserva el historial en estado Anulada, libera stock reservado sin movimiento físico ni kardex y deja la seña a favor usando el pago original. No mueve caja al cancelar. El reembolso posterior requiere la condición reembolsable guardada, Reembolsar:Sena y una caja abierta de hoy en la sucursal.
* El vencimiento de una reserva vigente puede ampliarse desde su detalle con View:Venta y Ampliar:Reserva, motivo obligatorio y fecha posterior a la vigente. Cada cambio registra fechas anterior y nueva, usuario, momento y motivo. El permiso no depende del nombre de un rol y puede otorgarse directamente a usuarios.
* Creación y ampliación proponen las 23:59 como hora inicial editable. Al ampliar se propone el día siguiente al vencimiento actual. El historial se guarda en ampliaciones_reserva. El permiso Ampliar:Reserva se asigna por defecto al supervisor; la gestión visual de permisos individuales queda para más adelante.
* Si la seña alcanza o supera el total, el aviso junto al botón orienta a Contado únicamente cuando el cliente paga y retira los productos ahora. Reservar con pago íntegro y retiro posterior no está implementado.
* El Mostrador permite navegar, consultar y preparar el carrito sin caja. Confirmar ventas o reservas exige caja abierta de hoy en la sucursal. El acceso a Caja abre otra pestaña y permite actualizar el estado conservando el carrito.
* Por decisión del 7 de noviembre de 2026, los adelantos sin productos y la aplicación de un mismo pago a varias ventas quedan pospuestos para una ampliación futura, sin fase asignada y sin bloquear el cierre de la fase 6. Por ahora, las señas se reciben al crear reservas con productos y los abonos se registran por separado en cada venta. El saldo a favor ya disponible mantiene su funcionamiento actual.
* La anulación de una nota completada conserva la venta y sus cobros en el historial. Repone las unidades vigentes, cancela el saldo pendiente y registra el reembolso de lo efectivamente cobrado en la caja abierta. Usa View:Venta y Update:Venta mediante la policy.
* Las correcciones de productos o cantidades se guardan en correcciones_venta, vinculadas a la venta original, con usuario, fecha y motivo. Requieren Corregir:Venta. El cierre original conserva sus movimientos y la caja actual registra únicamente las diferencias de dinero realizadas. Los productos que se conservan mantienen el precio guardado; los agregados usan la lista actual del cliente. El cobro adicional de contado está implementado en efectivo.
* El detalle distingue productos actuales y originales, historial de correcciones, cobros y reembolsos. El bloque independiente para ingresar abonos por código fue retirado del Mostrador.
* Las devoluciones físicas de productos quedaron en la fase 7, cerrada el 11 de noviembre de 2026. Las correcciones de esta sección atienden errores de registro.
* Por decisión del 11 de noviembre de 2026, la fase 8 (talonario y factura manual) queda pospuesta. No bloquea las fases siguientes. La venta sigue como nota de venta y el talonario de papel se lleva afuera del sistema, adjuntando la nota a la libreta. El diseño de `talonarios` y `factura_manual` queda escrito para cuando un local necesite controlar esas hojas desde el ERP.

### Fase 7: política comercial de devoluciones

Configuración implementada el 9 de noviembre de 2026 en Configuración del negocio → Devoluciones. Se reutiliza negocio_configuracion, aislada por tenant, con estos campos:

* acepta_devoluciones: booleano, inicialmente falso. Afecta devoluciones comerciales; no bloquea reclamos por fallas o garantía.
* dias_devolucion: entero de 0 a 3650. Cero significa el mismo día de entrega; N significa N días calendario adicionales, hasta las 23:59 del último día. Sin plazo ilimitado implícito.
* devolucion_permite_cambio: booleano, inicialmente verdadero. Habilita el cambio comercial cuando acepta_devoluciones también está activo.
* devolucion_permite_saldo_favor: booleano, inicialmente falso.
* devolucion_permite_reembolso: booleano, inicialmente falso.
* devolucion_permite_excepcion: booleano, inicialmente falso. Diseñado para excepciones al plazo; no habilita devoluciones globalmente desactivadas ni permite superar cantidades o importes disponibles.

La pantalla exige al menos una resolución cuando acepta_devoluciones está activado. El permiso actual View:ConfiguracionDelNegocio controla acceso y guardado, según la policy existente. Al desactivar devoluciones, el plazo, las resoluciones y las excepciones quedan deshabilitados y no se sobrescriben al guardar; conservan sus valores para la próxima activación. La configuración no se aplica a una operación por sí misma.

Diseño y avance del flujo al 11 de noviembre de 2026:

1. Implementado: ventas.politica_devolucion_snapshot guarda una copia JSON versionada de las condiciones y zona horaria al registrar contado, crédito o reserva. Cambiar la configuración no modifica esas ventas. La captura proviene de NegocioConfiguracion::politicaDevolucion(), nunca del contenido enviado por el navegador.
2. Implementado: ventas.entregada_en registra la confirmación en contado y crédito con entrega inmediata, y el retiro en reservas. En una reserva sin entregar permanece nulo. La entrega conserva la política original; ni los abonos ni las ampliaciones reinician la fecha de entrega.
3. Implementado: RegistrarDevolucion registra notas_credito_devoluciones y nota_credito_detalles para piezas recibidas en buen estado. Usa las líneas vigentes tras correcciones, descuenta devoluciones previas y valida cantidades e importes bajo bloqueo en una transacción. El precio, descuento y costo salen de la venta, no de la tarifa actual. El descuento global se reparte proporcionalmente entre líneas. El redondeo acumulado de importes y cantidades base evita devolver centavos de más o de menos y repone exactamente el stock vendido al devolver toda la línea. Las cantidades demasiado pequeñas para representar un reingreso a cuatro decimales se rechazan.
4. Implementado: permisos Registrar:Devolucion y Autorizar:ExcepcionDevolucion, independientes del nombre de rol. El supervisor los recibe como plantilla inicial. La excepción solo amplía el plazo si la política guardada lo permite; exige motivo y conserva responsable, fecha y motivo_excepcion. No habilita una venta que no aceptaba devoluciones.
5. Decisión de desarrollo local: no implementar compatibilidad para ventas históricas. Se ajustó la migración original de ventas y se recreó el único pool local de prueba, conservando el tenant central y recreando su acceso God con las credenciales demo acordadas. DemoLocalSeeder repuso 2 sucursales y 5 productos; quedaron 0 ventas. En producción las migraciones ya desplegadas se mantendrán y los cambios serán incrementales.
6. Implementado: acción Registrar devolución desde el detalle compartido en Mis ventas, Caja y Cajas del día. Dos pasos: productos/motivo/resolución y revisión con confirmación. Valida el plazo hasta el final del día en la zona horaria guardada. La acción queda deshabilitada con explicación si la política de esa venta solo permite cambios o no acepta devoluciones. La nota original sigue completada y su total vigente corresponde a las piezas conservadas. Los formularios de devolución y cambio muestran las líneas que aún tienen unidades. Si una está excluida, la cantidad queda en cero y dice «No retornable». Cuando no queda ninguna pieza que se pueda devolver, ambas acciones quedan deshabilitadas. Si ya volvieron, el aviso lo dice. Si las que quedan están excluidas, nombra el SKU y el motivo. El servidor sigue rechazando una cantidad sobre una línea excluida.
7. Resoluciones implementadas: reembolso por métodos originales y saldo a favor de un cliente identificado. En crédito, primero baja la deuda y solo el excedente sobre esa deuda se resuelve contra cobros aprobados. El saldo a favor utilizado siempre se restituye como saldo, aunque se elija reembolso. El dinero devuelto crea Pago de reembolso en la caja abierta de hoy del operador, en la sucursal original; no modifica la caja cerrada de la venta. Generar/restaurar saldo o reducir deuda no crea un pago ni exige caja abierta. Un reembolso electrónico se registra por su método original después de realizarlo externamente; no hay ejecución bancaria automática.
8. Inventario: reingreso al almacén original, kardex de tipo devolucion con referencia nota_credito_devolucion y costo congelado de la línea. El PPP se recalcula ponderando ese costo y las existencias físicas actuales de todos los almacenes. Toda la operación se revierte si falla. Una revisión desactualizada, pago pendiente, cantidad excesiva o acceso a otro tenant se rechaza.

9. Cambiar producto: disponible desde el detalle de una nota de contado entregada y pagada, cuando la política de la venta lo permite. El operador necesita permiso para registrar devoluciones y ventas y una caja abierta de hoy en la sucursal original. Recibe piezas en buen estado y entrega un reemplazo del mismo almacén. Se aplica el importe realmente pagado por las piezas devueltas al precio vigente del reemplazo. Si cuesta más, se cobra la diferencia; si cuesta menos, solo se ofrece reembolso o saldo a favor según la política guardada. Un consumidor final puede hacer el cambio por su número de venta. Para guardar saldo a su favor, se identifica al cliente sin editar la venta original. Una nota de reemplazo y las aplicaciones de los cobros originales enlazan la operación. El reingreso, la salida, la diferencia y el vínculo se confirman en una transacción.
10. Presentación del cambio: la nota original muestra **Cambio completado**, el importe aplicado, el valor del reemplazo y la diferencia cobrada, reembolsada o guardada como saldo. Si volvieron todas las piezas, el detalle lo indica. **Ver reemplazo** abre la otra nota y **Ver venta original** permite consultarla desde el reemplazo. La lista identifica la nota nueva como **Reemplazo**.
11. **Anular venta** revierte toda una nota completada y repone sus piezas. Requiere permiso de anulación, ausencia de pagos pendientes y caja abierta de hoy en la sucursal original si hay que devolver dinero. Se bloquea si la nota ya tiene una devolución o cambio, o si es la nota de reemplazo. Las piezas restantes de la original se gestionan desde esa nota. Si vuelve la pieza entregada como reemplazo, se revisa desde su propia nota, según su política.

12. Implementado el 11 de noviembre de 2026: exclusiones comerciales. En la categoría, el interruptor «No se devuelve ni se cambia» (`categorias.sin_devolucion_comercial`, default falso) bloquea el rubro. En el producto, «Devolución comercial» (`productos_maestros.devolucion_comercial`) vale `hereda`, `permite` o `bloquea`; el default es `hereda` y el producto gana. `permite` reabre un rubro bloqueado. `bloquea` cierra esa pieza aunque el rubro permita devolver. Al vender, la línea copia `sin_devolucion_comercial` y `motivo_sin_devolucion`: el nombre del producto si él bloquea, o el nombre del rubro si hereda un rubro bloqueado. Cambiar la ficha después no cambia esa venta. Una corrección conserva la marca de la línea original y resuelve la marca actual solo si la línea es un producto nuevo. En una venta mixta, la línea excluida se ve y no se puede elegir. Si todas las piezas que quedan están excluidas, «Registrar devolución» y «Cambiar producto» quedan apagados y el aviso dice «No se puede devolver ni cambiar {SKU}. No retornable: {motivo}.» No hay autorización de encargado para saltear la marca. No bloquea un reclamo por falla. Se probó en el negocio local con el Rodamiento 6204-2RS marcado «No se devuelve» y la nota VNT-000005.

Fase 7 queda cerrada el 11 de noviembre de 2026 con el alcance comercial acordado. El flujo separado de piezas dañadas y garantía queda para más adelante (ítem 15 de «Cosas a tener en cuenta a futuro»).

Verificación de este avance: 14 pruebas enfocadas y 118 comprobaciones aprobadas, incluyendo devolución parcial, crédito, saldo a favor reutilizado, venta corregida, métodos combinados, plazo/excepción, aislamiento entre tenants y redondeo acumulado de importes y stock. Build de assets y Pint correctos.

Verificación del cambio: 34 pruebas de regresión de ventas, caja y devoluciones, y 3 pruebas enfocadas del detalle y la navegación entre notas. En el pool local se probó un cambio de BOB 45.00 a BOB 52.00 con cobro de BOB 7.00 y se verificó el acceso entre ambas notas.

Verificación del 11 de noviembre de 2026: suite completa aprobada con 199 pruebas y 1844 comprobaciones. Incluye la devolución de una línea de una venta con dos productos y el bloqueo de ambas acciones al devolver la última unidad. También verifica los filtros del kardex y su aislamiento entre negocios.

Referencias de diseño: [Shopify POS](https://help.shopify.com/es/manual/sell-in-person/shopify-pos/order-management/return-rules) para plazos, condiciones guardadas y excepciones; [Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/commerce/refund_policy_returns) para resoluciones y métodos de reembolso. La política comercial no sustituye las obligaciones sobre reclamos o garantías.

### Cosas a tener en cuenta a futuro

Implementado el 9 de noviembre de 2026 como ampliación posterior al cierre de fase 6: vuelto/cambio en Mostrador, señas, abonos y retiro de reservas. pagos.monto conserva lo aplicado; efectivo_recibido y cambio_entregado guardan recibido y vuelto en cada cobro en efectivo. El servidor calcula el cambio, caja registra solo monto y una anulación devuelve lo aplicado. Los datos históricos quedan vacíos. QR, tarjeta y transferencia no generan cambio automático. Ver alcance y límites en fase_6_estado_actual.md.

Ítems conscientes que **no** se implementan en v1, para no rediseñar a ciegas después:

1. **Listas de precios (estilo Odoo / SAP).** Hoy: `clientes.tipo_precio` = `menor` | `mayor` y en el producto `precio_venta` / `precio_mayor`. Sirve al mostrador y al taller. Más adelante: catálogo de listas por tenant + reglas (producto, categoría, cantidad mínima, descuento %, vigencia) y el cliente apunta a una lista; la venta puede sobreescribir la lista en ese pedido. Ver nota en el punto 25.
2. **Precio de promo por producto (gestión de productos).** Hoy no hay cuarto precio. El mostrador no ofrece descuento por ítem. Cuando se haga: campos en la variante (precio o % + `promo_desde` + `promo_hasta`), editados en el recurso de productos. El mostrador aplica solo si hoy está en el rango y puede mostrar el precio de lista tachado. No usar el descuento de línea del cajero para una campaña. Si hace falta promocionar un rubro entero, no sumar columnas: ir a campaña o a listas (ítem 1). Ver nota en el punto 25.
3. **Autorizar descuento con contraseña.** Hoy el tope se valida y, si se pasa, falta `supervisor_descuento_id`, pero el mostrador no pide clave. Pendiente: (a) dentro del tope, el cajero reingresa su contraseña para dejar huella; (b) pasado el tope, modal con usuario y contraseña de un `supervisor` del mismo negocio, y se guarda ese id. No usar un select de supervisor sin clave. El mismo modal puede reutilizarse en anulación y devolución. Ver nota en el punto 25.
4. **Moneda ISO abierta.** Hoy el enum de moneda del negocio es BOB/PEN/BRL. Pendiente pasar a código ISO de 3 letras. Ver nota en el punto 26.
5. **Documento de factura en la venta.** El cliente ya puede guardar varios documentos (CI, NIT, etc.) con atributos dinámicos. Al facturar habrá que elegir **un** tipo + número (y complemento si aplica) para ese comprobante, sin mezclar varios en la misma factura.
6. **Listado y arqueo de cajas (ampliar).** Ya hay cierre multi-medio y **Cajas del día**. Un turno de otro día pasa a pendiente de contar y no recibe más ventas: se puede abrir el de hoy. Un turno sin ventas se anula. El faltante del día solo suma turnos cerrados. Falta: sucursales a cargo y caja chica (fase 10). Una abierta por cajero+sucursal.
7. **Baja de usuarios / vendedores (no borrar a ciegas).** Ventas, cajas, compras, kardex, autorizaciones de supervisor, etc. apuntan a `users` del pool. Reglas a diseñar cuando exista administración de usuarios del negocio:
   * **Borrado físico bloqueado** si hay filas relacionadas (FK Restrict o chequeo en la app): no se pierde el historial ni se rompe la integridad.
   * **Preferir eliminación lógica** (`deleted_at` / `is_active` / baja): el usuario deja de aparecer en selects de asignación (vendedor, cajero, responsable) y no puede iniciar sesión, pero las ventas y cajas viejas siguen mostrando quién las hizo.
   * **Al dar de baja**, preguntar si quiere **reasignar** lo pendiente o la titularidad operativa a otro usuario del mismo negocio (ej.: ventas en reserva a su nombre, caja abierta —que debería cerrarse antes—, cartera o reportes “por vendedor”). Es un flujo de ejemplo; al implementarlo definir qué se reasigna y qué queda histórico con el usuario original.
8. **Pasarela de pagos / S3.** Hoy el cobro de contado registra a mano `efectivo`, `qr_externo`, `voucher_tarjeta` y `transferencia`, con adjunto en disco local (tenancy). Los handlers viven en `App\Ventas\Pagos` (factory). Más adelante: S3 para comprobantes e integración real de QR/tarjeta sin cambiar el enum ni el flujo de `pagos`.
9. **Reseed de roles/permisos tras deploy (tenants ya vivos).** `SeedNegocioRoles` corre al **crear** el negocio. Si después se agregan modelos, pages Filament o permisos Shield (`Venta`, `View:MostradorDelNegocio`, etc.), los tenants viejos **no** los reciben solos: el menú puede ocultar pantallas aunque el rol diga «todo», porque faltan filas en `permissions` o el `syncPermissions` del `supervisor`/`cajero` no se volvió a correr. Checklist consciente hasta automatizarlo:
   * Migrar el pool tenant (`database/migrations/tenant`).
   * Volver a ejecutar el seed de roles **en cada tenant** (comando Artisan tipo `negocio:resync-roles`, job post-deploy, o hook de **Laravel Cloud** en cada deploy).
   * Limpiar caché de permisos Spatie.
   * Alinear `canAccess` de pages con el permiso que Shield muestra (página y/o policy del modelo).
   * Decidir si el resync del supervisor **pisa** permisos custom del negocio o solo **agrega** los nuevos (hoy el seed hace `syncPermissions` completo del listado canónico).
10. **Lista `tenant-pools.tables` (limpieza del pool).** En el tenancy híbrido las tablas con `tenant_id` se declaran a mano en `config/tenant-pools.php`, en orden de borrado (hijas antes que padres), para que `CleanTenantPoolData` limpie al eliminar un negocio. Es sostenible en v1 si la regla de equipo es: *migración tenant ⇒ actualizar esa lista*. A futuro, si se olvida seguido: test/CI que falle si hay tabla con `tenant_id` fuera del config; comando que valide el orden; o limpieza diferida (soft-delete del tenant + job). No hace falta reescribir el modelo de pools solo por esto.
11. **Sucursales del usuario e inicio de turno.** Hoy no se asigna sucursal al crear el usuario. El mostrador y la caja no limitan por persona: cualquier cajero ve todas las sucursales. Pendiente: al crear/editar el usuario, asignar una o más sucursales (`user_sucursales`). Al iniciar (o con un **switch de sucursal en el menú**), elige en cuál trabaja; si tiene una sola, entra ahí. La sesión guarda esa sucursal y filtra la **operación** (ventas, caja, Cajas del día, stock operativo, talonario, listados). Para ver otra de las suyas, cambia el switch. No vender ni abrir caja en una que no le pertenece. **Excepción:** usuarios y roles no van por el switch (son centrales del tenant). Un selector extra en una pantalla (ej. otra fecha/sucursal en Cajas del día) puede coexistir encima del contexto, pero el default es el switch. No poner `sucursal_id` fijo en `users`. Ver punto 15 y futuro ítem 14.
12. **Del catálogo al mostrador.** A veces el vendedor encuentra la pieza en el resource de productos (ficha, atributos, foto) y después la tiene que buscar de nuevo en el cobro. Pendiente, para no frenarlo: en la tabla o ficha de la variante, (a) **copiar SKU** (y si hay, código de barras) al portapapeles, y/o (b) **Agregar al mostrador** / Vender: abre `MostradorDelNegocio` con esa variante ya en el carrito (o con el buscador precargado). No duplicar el cobro dentro del catálogo. Si no hay caja abierta, el mostrador avisa como hoy. Ver fase 2 y fase 5.
13. **Entrada express / compra flash (cierre fase 5).** Decisiones resueltas en el punto 5. Confirmación mediante check obligatorio, ficha mínima, proveedor ocasional con reasignación desde Compras y sin movimiento de caja. La factura posterior no genera otra entrada; corrección de costo por el procedimiento de fase 9.
14. **Roles dinámicos y plantilla `encargado`.** Hoy se siembran `cajero` y `supervisor`, y parte del código/docs aún habla del nombre del rol. Dirección: (a) autorizar solo por permiso Shield/Spatie; (b) sembrar también `encargado` (matriz: usuarios, roles, sucursales, config + operación de todas las sucursales); (c) dejar `supervisor` operativo sin administración del sistema; (d) no habilitar `super_admin` de Shield; (e) no crear roles por sucursal. Flujo de negocio: matriz + sucursales; la encargada de matriz ve todo y por convención crea usuarios para delegar. Roles custom en Shield (solo stock, etc.) sin tocar código. Coordinar con el resync del ítem 9 y con el switch del ítem 11. Ver punto 15.

15. **Reclamos de garantía y piezas dañadas.** Propuesta para más adelante, separada de las devoluciones y cambios comerciales de piezas en buen estado.
    - **Recepción.** Desde el detalle de la nota original, `Registrar reclamo` vincula el caso a la línea vendida. El vendedor indica cantidad, falla reportada y si el cliente dejó la pieza en el local. El reclamo queda **Pendiente de revisión**. La recepción no mueve caja ni suma stock vendible. Mientras el caso esté abierto, las unidades reclamadas no pueden tramitarse en otra devolución o cambio.
    - **Revisión.** Una lista de **Reclamos pendientes** permite que alguien con permiso registre la decisión, el motivo y qué se hizo con la pieza. La respuesta al cliente (reemplazo, reembolso o rechazo) y el destino de la pieza se registran por separado. Si se entrega un reemplazo, sale del stock vendible y queda vinculado al reclamo. Si se rechaza y la pieza estaba en custodia del local, se registra su entrega al cliente.
    - **Alcance posterior.** Definir cómo registrar la baja de piezas dañadas, las órdenes de reparación y el seguimiento con proveedores cuando el negocio necesite esos pasos.
    - **Referencias.** Shopify permite decidir si una pieza devuelta vuelve al stock. Dynamics 365 separa recepción, inspección y resolución. Odoo lleva las reparaciones en una orden propia. [Shopify POS](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/order-management/complete-refund-orders) · [Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/supply-chain/sales-marketing/sales-returns) · [Odoo](https://www.odoo.com/documentation/master/applications/inventory_and_mrp/repairs/repair_orders.html).

---

## PARTE 2: ESTRUCTURA RELACIONAL DE LA BASE DE DATOS

Convención de esta parte: todas las tablas viven en el pool. `tenant_id` es string y va primero en índices y únicos. Toda foreign key interna es `(tenant_id, *_id)` hacia `(tenant_id, id)` de la tabla padre. `users` es la tabla de usuarios del negocio, no la del admin de la plataforma.

* **Users** (Usuarios del negocio. La tabla ya existe en el pool. El rol no es una columna)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`, y único `(tenant_id, email)`
  * `name` (varchar)
  * `email` (varchar)
  * `password` (varchar)
  * `is_active` (boolean, Default: true)
  * `timestamps` (timestamp)
  * El rol es de Shield de ese negocio (plantillas: hoy `cajero` / `supervisor`; dirección: sumar `encargado`). Autoriza el **permiso**, no el nombre del rol. El cajero no administra roles. No hay roles por sucursal.
  * Sin sucursales asignadas en v1. Cuando exista, no va `sucursal_id` en `users`: va una pivote `user_sucursales` (`tenant_id`, `user_id`, `sucursal_id`). Al crear el usuario se eligen. Contexto de trabajo (inicio o switch de menú) filtra la operación; usuarios y roles quedan fuera del filtro. Ver punto 15 y futuros ítems 11 y 14.
* **Negocio_Configuracion** (Una fila por negocio)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único
  * `horas_reserva` (int, Default: 48) -- Plazo de partida de una seña. Cada reserva puede durar menos o más
  * `descuento_max_porcentaje` (decimal 5,2, Default: 10) -- Tope del cajero, en menor y en mayor
  * `descuento_max_importe` (decimal 10,2, Default: 0.00) -- Tope en la moneda de la tienda. En 0, un importe fijo lo autoriza el supervisor
  * `moneda` (enum: 'BOB', 'PEN', 'BRL') -- Default: 'BOB'. Bs, sol o real. Es la moneda de la caja, los precios y el costo (Pendiente: migrar a `varchar(3)` con código ISO 4217 para admitir cualquier país/moneda)
  * `modo_moneda` (enum: 'solo_moneda', 'con_dolar') -- Default: 'solo_moneda'. El dólar es referencia. No se cobra en dólares
  * `siguiente_numero_venta` (bigint, Default: 1) -- Siguiente `VNT-`. Cabe hasta 19 dígitos. Se reserva al guardar, con la fila bloqueada, para que dos cajeros no saquen el mismo
  * `timestamps` (timestamp)
* **Tipos_Cambio** (Solo si `modo_moneda = 'con_dolar'`. El dólar del día, contra la moneda de la tienda)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `valor` (decimal 10,4) -- Unidades de la moneda de la tienda por un dólar. Ejemplo: 11 en Bolivia, 3.70 en Perú. CHECK `valor > 0`
  * `vigente` (boolean, Default: true) -- Solo uno vigente por negocio
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Rol Shield `supervisor`
  * `timestamps` (timestamp)

### 1. Módulo de Sucursales y Almacenes
* **Sucursales**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`. Ej: Sucursal Central, Sucursal 4 Anillo
  * `direccion` (text, Nullable)
  * `telefono` (varchar, Nullable)
  * `is_active` (boolean, Default: true)
  * `timestamps` (timestamp)
* **Almacenes**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`. Sin `sucursal_id`: quién lo usa lo dice la pivote
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`. Ej: Depósito Principal, Estante A-1
  * `timestamps` (timestamp)
* **Sucursal_Almacenes** (Tabla pivote N:M. Una fila: almacén propio. Dos filas del mismo almacén: saldo compartido)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `sucursal_id` (int, Foreign Key compuesta con `tenant_id` -> Sucursales)
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `es_principal` (boolean, Default: false) -- Principal de esa sucursal, no del almacén
  * `timestamps` (timestamp)
  * Único `(tenant_id, sucursal_id, almacen_id)` -- Destino de la foreign key de `ventas`
  * Una sola fila con `es_principal = true` por `(tenant_id, sucursal_id)`

### 2. Módulo de Productos e Inventarios (Kardex y PPP)
* **Categorias**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`
  * `sin_devolucion_comercial` (boolean, Default: false) -- Si está activo, las piezas del rubro no entran en devolución ni cambio comercial, salvo que el producto diga `permite`
  * `timestamps` (timestamp)
  * La ficha del rubro son las filas de `categoria_atributos`. No hay columna `plantilla_atributos`.
* **Unidades_Medida** (Catálogo del negocio. Una fila: Milímetro, mm, longitud, atributo. Otra: Kilogramo, kg, masa, cantidad)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`. Ej: Milímetro, Pulgada, Voltio, Kilogramo
  * `simbolo` (varchar) -- Único `(tenant_id, simbolo)`. Ej: mm, in, V, kg
  * `magnitud` (enum: 'longitud', 'electrico', 'presion', 'conteo', 'masa', 'volumen') -- La pantalla agrupa por esto. Solo se convierte dentro de la misma magnitud
  * `uso` (enum: 'atributo', 'cantidad') -- `atributo` mide un dato del rubro (diámetro en mm). `cantidad` compra, stock y venta. No se mezclan
  * `factor_a_referencia` (decimal 18,6, Default: 1) -- Cuántas unidades de la referencia de esa familia vale una de esta. El gramo es 1. El kilo es 1000. La pieza es 1
  * `decimales` (int, Default: 0) -- Cuántos decimales acepta al tipear. La pieza es 0. El kilo es 3
  * `es_referencia` (boolean, Default: false) -- La unidad contra la que se miden las demás de su familia
  * `timestamps` (timestamp)
* **Categoria_Atributos** (Una fila por campo de la ficha. Se crea antes de borrar `categorias` y `unidades_medida`)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `categoria_id` (int, Foreign Key compuesta con `tenant_id` -> Categorias)
  * `clave` (varchar) -- Único `(tenant_id, categoria_id, clave)`. Identidad estable. Ej: `diametro_interno`, `rosca`, `casquillo`. No incluye la unidad
  * `etiqueta` (varchar) -- Lo que ve el operador. Ej: Diámetro interno
  * `tipo` (enum: 'decimal', 'texto', 'lista')
  * `obligatorio` (boolean, Default: false)
  * `orden` (int) -- Orden en la ficha y en el filtro
  * `unidad_medida_id` (int, Foreign Key compuesta con `tenant_id` -> Unidades_Medida, Nullable) -- Solo en un decimal que se mide. Nulo en texto, en lista y en un decimal sin escala
  * `opciones` (jsonb, Nullable) -- Solo si `tipo = 'lista'`. Ej: `["2RS", "ZZ", "ABIERTO"]`
  * `timestamps` (timestamp)
  * CHECK: si `tipo` es `texto` o `lista`, `unidad_medida_id` es nulo
  * CHECK: si `tipo` es `lista`, `opciones` no es nulo. En `decimal` y `texto`, `opciones` es nulo
  * Si el atributo ya tiene unidad, la aplicación solo acepta otra unidad de la misma `magnitud`
* **Marcas** (Catálogo del negocio. Una fila: Toyota. Se borra después de `productos_maestros`)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único por negocio en minúsculas. "Toyota" y "toyota" son la misma fila. Se muestra como se escribió
  * `timestamps` (timestamp)
* **Productos_Maestros**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Ej: Rodamiento rígido de bolas
  * `categoria_id` (int, Foreign Key compuesta con `tenant_id` -> Categorias)
  * `marca_id` (int, Foreign Key compuesta con `tenant_id` -> Marcas, Nullable) -- La marca es del producto. Si no se conoce, queda vacío
  * `unidad_base_id` (int, Foreign Key compuesta con `tenant_id` -> Unidades_Medida) -- Unidad del stock, del kardex, del PPP y del precio. Si no se elige, queda la pieza. No se cambia si ya hay movimientos
  * `imagenes` (jsonb/json, Nullable) -- Galería multimedia del producto almacenada en S3
  * `devolucion_comercial` (varchar, Default: 'hereda') -- `hereda` sigue al rubro. `permite` se puede devolver aunque el rubro esté bloqueado. `bloquea` no se devuelve ni se cambia
  * `timestamps` (timestamp)
* **Producto_Unidades** (Empaque de un producto. Una caja de 100 tornillos)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `producto_maestro_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Maestros, On Delete: Cascade)
  * `unidad_medida_id` (int, Foreign Key compuesta con `tenant_id` -> Unidades_Medida)
  * `factor_a_base` (decimal 18,6) -- Cuántas unidades base entran en una de esta. 1 caja = 100 piezas
  * `timestamps` (timestamp)
  * Único `(tenant_id, producto_maestro_id, unidad_medida_id)`
* **Productos_Variantes** (El único ítem vendible)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `producto_maestro_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Maestros)
  * `sku` (varchar) -- Único `(tenant_id, sku)`. Ej: 6204-2RS
  * `codigo_barras` (varchar, Nullable) -- Único `(tenant_id, codigo_barras)`. El vacío puede repetirse
  * `imagen` (varchar, Nullable) -- Foto específica de la variante en S3 (opcional, si difiere de la galería general)
  * `atributos_json` (jsonb) -- Solo valores, con la clave de `categoria_atributos`. Un decimal es número JSON (`20`). Un texto o una opción de lista es texto (`"3/4-16 UNF"`, `"2RS"`). La unidad no se guarda acá. Cast `array` en Laravel. La variante no agrega claves fuera de su categoría
  * `precio_venta` (decimal 10,2) -- Precio al por menor de una unidad base, en la moneda de la tienda
  * `precio_mayor` (decimal 10,2, Nullable) -- Precio mayorista de una unidad base. Si está vacío, el mayorista paga `precio_venta`
  * `precio_venta_dolar` (decimal 10,2, Nullable) -- Si tiene valor, al confirmar un tipo de cambio nuevo se propone `precio_venta`
  * `precio_mayor_dolar` (decimal 10,2, Nullable) -- Igual, para el precio mayorista
  * `precio_costo` (decimal 14,4) -- Costo Promedio Ponderado (PPP) actual de una unidad base, en la moneda de la tienda
  * No hay `precio_promo` en v1. Cuando exista, se carga en esta ficha (gestión de productos) con vigencia, no en el mostrador. Ver punto 25 y futuro ítem 2.
  * `timestamps` (timestamp)
  * Índice GIN sobre `atributos_json` (solo PostgreSQL) -- Igualdad de uno o varios atributos dentro del negocio y de la categoría. Ej: `atributos_json @> '{"diametro_interno": 20, "diametro_externo": 47, "ancho": 14}'`. El rango numérico no usa este índice: se compara después de acotar por negocio y categoría. En SQLite queda json sin GIN

* **Inventario_Stocks**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `ubicacion` (varchar, Nullable) -- Ej: PASILLO-01-ESTANTE-04-ALTURA-02. Es del almacén, no de la variante
  * `cantidad_actual` (decimal 14,4, Default: 0) -- Stock físico en la unidad base, señas incluidas. 0.250 kg si la base es el kilo
  * `cantidad_reservada` (decimal 14,4, Default: 0) -- Suma de `cantidad_base` de las líneas de ventas en `reserva` de esta variante y este almacén. Si una seña vence o se anula, esa suma baja
  * `timestamps` (timestamp)
  * Único `(tenant_id, producto_variante_id, almacen_id)`
  * CHECK `cantidad_actual >= 0`, `cantidad_reservada >= 0` y `cantidad_reservada <= cantidad_actual`
* **Movimientos_Inventario** (Kardex físico y financiero. Solo inserción)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Responsable del movimiento
  * `tipo_movimiento` (enum: 'entrada_compra', 'salida_venta', 'devolucion', 'traslado_salida', 'traslado_entrada', 'ajuste', 'entrada_flash_emergencia', 'correccion_costo')
  * `cantidad` (decimal 14,4) -- En la unidad base. Positivo o negativo según corresponda. Cero solo en `correccion_costo`
  * `stock_previo` (decimal 14,4) -- En la unidad base
  * `stock_posterior` (decimal 14,4) -- En la unidad base
  * `costo_unitario` (decimal 14,4) -- Costo de una unidad base en la transacción
  * `costo_promedio_resultante` (decimal 14,4) -- PPP calculado tras el movimiento
  * `referencia_tipo` (varchar, Nullable) -- `venta`, `orden_compra`, `nota_credito`, `traslado`, `ajuste` o `correccion_costo`
  * `referencia_id` (int, Nullable) -- Id de esa tabla, del mismo `tenant_id`. La aplicación lo comprueba
  * `timestamps` (timestamp)
* **Ajustes_Inventario** (Documento de ajuste puntual o de conteo)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Quien carga las cantidades
  * `supervisor_id` (int, Foreign Key compuesta con `tenant_id` -> Users, Nullable) -- Obligatorio al cerrar. Rol Shield `supervisor`
  * `tipo` (enum: 'puntual', 'conteo')
  * `estado` (enum: 'en_curso', 'cerrado') -- El puntual se cierra en el acto. El conteo lo cierra el supervisor
  * `observacion` (text, Nullable) -- Obligatoria si `tipo = 'puntual'`
  * `timestamps` (timestamp)
* **Ajuste_Detalles**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `ajuste_id` (int, Foreign Key compuesta con `tenant_id` -> Ajustes_Inventario, On Delete: Cascade)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `motivo` (enum: 'merma', 'rotura', 'hallazgo', 'uso_interno', 'diferencia_conteo')
  * `cantidad_sistema` (int, Nullable) -- Se copia del stock al cerrar, no al empezar
  * `cantidad_fisica` (int) -- Lo que hay en el estante, señas incluidas
  * `timestamps` (timestamp)
  * Al cerrar, la diferencia es `cantidad_fisica - cantidad_sistema`. Si no es cero, nace un movimiento `ajuste`. Si `cantidad_fisica` deja el stock bajo `cantidad_reservada`, el cierre no pasa. El PPP no cambia.
* **Correcciones_Costo** (El costo tipeado en una compra estaba mal. No cambia cuántas piezas hay)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `orden_compra_detalle_id` (int, Foreign Key compuesta con `tenant_id` -> Orden_Compra_Detalles)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes) -- El almacén de esa compra, para la línea de kardex
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `supervisor_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Rol Shield `supervisor`
  * `costo_anterior` (decimal 14,4)
  * `costo_nuevo` (decimal 14,4)
  * `observacion` (text)
  * `timestamps` (timestamp)
  * Nace un movimiento `correccion_costo` con cantidad cero. `precio_costo` de la variante pasa a `costo_nuevo`. Las ventas ya cerradas conservan su snapshot. La compra no se edita.

* **Traslados** (Dos saldos distintos. No se usa cuando el almacén es compartido)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `almacen_origen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `almacen_destino_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `estado` (enum: 'pendiente', 'completado', 'anulado') -- Default: 'pendiente'
  * `timestamps` (timestamp)
  * CHECK `almacen_origen_id <> almacen_destino_id`
* **Traslado_Detalles**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `traslado_id` (int, Foreign Key compuesta con `tenant_id` -> Traslados, On Delete: Cascade)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `cantidad` (int) -- CHECK `cantidad > 0`
  * `timestamps` (timestamp)

### 3. Módulo de Clientes (Gestión Comercial y Fiscal)
* **Clientes** (Sin moneda propia. Paga en la moneda de la tienda)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `tipo_cliente` (enum: 'persona_natural', 'empresa') -- Default: 'persona_natural'
  * `nombres` (varchar, Nullable) -- Obligatorio si persona natural
  * `apellidos` (varchar, Nullable) -- Obligatorio si persona natural
  * `razon_social` (varchar, Nullable) -- Obligatorio si empresa. En persona se arma con nombres + apellidos para listar y buscar
  * `tipo_precio` (enum: 'menor', 'mayor') -- Default: 'menor'. A futuro puede pasar a lista de precios (ver «Cosas a tener en cuenta a futuro»)
  * `telefono` (varchar, Nullable)
  * `email` (varchar, Nullable)
  * `direccion` (text, Nullable)
  * `timestamps` (timestamp)
* **Tipos_Documento_Identidad** (Catálogo configurable por negocio. Al nacer el tenant: CI y NIT)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`; único `(tenant_id, codigo)`
  * `codigo` (varchar) -- Ej: `ci`, `nit`, `pasaporte`, `licencia`
  * `nombre` (varchar)
  * `para_persona_natural` (boolean)
  * `para_empresa` (boolean)
  * `is_active` (boolean, Default: true)
  * `orden` (smallint, Default: 0)
  * `timestamps` (timestamp)
* **Tipo_Documento_Atributos** (Campos dinámicos del tipo: como `categoria_atributos`)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `tipo_documento_identidad_id` (int, FK compuesta)
  * `clave` (varchar) -- Único por tipo dentro del tenant
  * `etiqueta` (varchar)
  * `tipo` (enum: 'decimal', 'texto', 'lista')
  * `obligatorio` (boolean, Default: false)
  * `identifica` (boolean, Default: false) -- Entra en `cliente_documentos.identidad`
  * `orden` (int, Default: 0)
  * `opciones` (json/jsonb, Nullable) -- Solo si `tipo = lista`
  * `timestamps` (timestamp)
* **Cliente_Documentos**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `cliente_id` (int, FK compuesta -> Clientes, Cascade)
  * `tipo_documento_identidad_id` (int, FK compuesta -> Tipos_Documento_Identidad, Restrict)
  * `valores_json` (json/jsonb) -- Clave → valor según los atributos del tipo
  * `identidad` (varchar) -- Derivada de atributos con `identifica`; único `(tenant_id, tipo_documento_identidad_id, identidad)`
  * Único `(tenant_id, cliente_id, tipo_documento_identidad_id)` -- Un CI y un NIT por cliente, no dos CI
  * `timestamps` (timestamp)
  * Semilla CI: `numero` (texto, identifica), `complemento` (texto, identifica), `lugar_emision` (lista LP/SCZ/…). Semilla NIT: `numero` (texto, identifica). Persona y empresa pueden usar NIT.

### 4. Módulo de Proveedores, Contactos y Compras Flash
* **Trabajadores**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombres` (varchar)
  * `apellidos` (varchar, Nullable)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users, Nullable) -- Si también entra al sistema, es un usuario del mismo negocio
  * `is_active` (boolean, Default: true)
  * `timestamps` (timestamp)
* **Proveedores**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre_empresa` (varchar) -- Ej: Rodamientos SKF S.A. (Único campo obligatorio para creación al vuelo)
  * `nit` (varchar, Nullable) -- Único `(tenant_id, nit)`. El vacío puede repetirse
  * `es_ocasional` (boolean, Default: false) -- Uno solo por negocio, creado al nacer el tenant. Único parcial donde `es_ocasional = true`
  * `direccion` (text, Nullable)
  * `timestamps` (timestamp)
* **Proveedor_Contactos**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `proveedor_id` (int, Foreign Key compuesta con `tenant_id` -> Proveedores, On Delete: Cascade)
  * `nombres` (varchar)
  * `apellidos` (varchar, Nullable)
  * `cargo` (varchar, Nullable) -- Ej: Asesor Comercial
  * `telefono` (varchar, Nullable)
  * `email` (varchar, Nullable)
  * `es_principal` (boolean, Default: false)
  * `timestamps` (timestamp)
* **Ordenes_Compra** (Soporta Compras Normales y Entradas Flash de Emergencia)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `proveedor_id` (int, Foreign Key compuesta con `tenant_id` -> Proveedores) -- Proveedor ocasional del negocio, o el real
  * `proveedor_contacto_id` (int, Foreign Key compuesta con `tenant_id` -> Proveedor_Contactos, Nullable) -- Contacto que atendió la emergencia
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Usuario del pool que genera la orden
  * `almacen_destino_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes)
  * `es_compra_flash` (boolean, Default: false) -- Identificador si nació de una urgencia en mostrador
  * `estado` (enum: 'pendiente', 'recibida_parcial', 'completada', 'anulada') -- Default: 'completada' para flash
  * `subtotal` (decimal 10,2)
  * `porcentaje_impuesto` (decimal 5,2, Nullable) -- Si tiene número, el impuesto se cargó como porcentaje de este subtotal y al editar se vuelve a calcular. Vacío significa importe fijo. Un 0 es cero por ciento, no un importe. No sale del catálogo de impuestos
  * `impuestos` (decimal 10,2) -- Monto de la factura del proveedor. Es el porcentaje aplicado al subtotal, o el importe tipeado. Se suma al subtotal para el total. No entra al costo promedio. El costo de la línea se anota sin impuesto. Varios impuestos se desglosan cuando exista la tabla de impuestos; este campo guarda la suma
  * `total` (decimal 10,2)
  * `persona_entrega_nombre` (varchar, Nullable)
  * `persona_recibe_trabajador_id` (int, Foreign Key compuesta con `tenant_id` -> Trabajadores, Nullable)
  * `fecha_emision` (timestamp)
  * `fecha_recepcion` (timestamp, Nullable)
  * `timestamps` (timestamp)
* **Orden_Compra_Detalles**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `orden_compra_id` (int, Foreign Key compuesta con `tenant_id` -> Ordenes_Compra, On Delete: Cascade)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `unidad_medida_id` (int, Foreign Key compuesta con `tenant_id` -> Unidades_Medida) -- La unidad tipeada. Si no se elige, es la unidad base
  * `factor_a_base` (decimal 18,6) -- Congelado al guardar. Cuántas unidades base hay en una unidad tipeada
  * `cantidad_pedida` (decimal 14,4) -- Cantidad tipeada, en la unidad de la línea. 2 cajas, o 0.250 kg
  * `cantidad_recibida` (decimal 14,4, Default: 0) -- Lo ya ingresado, en la misma unidad tipeada, para comparar contra lo pedido
  * `cantidad_base` (decimal 14,4) -- `cantidad_pedida * factor_a_base`. Es lo que entra al stock
  * `moneda` (enum: 'local', 'USD') -- Default: 'local'. Local es la moneda de la tienda. USD solo si el negocio está en `con_dolar`. No se cobra en USD
  * `costo_anotado` (decimal 14,4) -- Precio de una unidad tipeada, en la moneda de la línea. 50 por caja
  * `costo_unitario_origen` (decimal 14,4) -- Lo anotado en esa moneda. Hoy es el mismo `costo_anotado`. El dólar sigue para la fase 11
  * `tipo_cambio_id` (int, Foreign Key compuesta con `tenant_id` -> Tipos_Cambio, Nullable)
  * `tipo_cambio_valor` (decimal 10,4, Nullable) -- Copia del dólar de ese momento. No cambia si mañana el dólar sube
  * `costo_unitario` (decimal 14,4) -- Costo de una unidad base, en la moneda de la tienda. Alimenta el PPP. Hoy es `costo_anotado / factor_a_base`
  * `subtotal_linea` (decimal 10,2)
  * `timestamps` (timestamp)

### 5. Módulo de Ventas y Comprobantes
* **Impuestos** (El porcentaje vigente. La venta guarda una copia)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`
  * `porcentaje` (decimal 5,2)
  * `is_active` (boolean, Default: true)
  * `timestamps` (timestamp)
* **Talonarios** (Solo factura manual física. Pospuesto con la fase 8 el 11 de noviembre de 2026. Una tienda que solo emite nota de venta no lo usa)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `sucursal_id` (int, Foreign Key compuesta con `tenant_id` -> Sucursales)
  * `tipo_comprobante` (enum: 'factura_manual')
  * `numero_desde` (int)
  * `numero_hasta` (int)
  * `estado` (enum: 'activo', 'agotado', 'anulado') -- Default: 'activo'
  * `timestamps` (timestamp)
  * CHECK `numero_desde <= numero_hasta`
  * Uno solo `activo` por `(tenant_id, sucursal_id, tipo_comprobante)`
  * Los rangos de la misma sucursal y tipo no se solapan. Lo comprueba la aplicación al crear
* **Talonario_Anulaciones** (Hoja muerta en papel. Se crea después de `notas_credito_devoluciones`)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `talonario_id` (int, Foreign Key compuesta con `tenant_id` -> Talonarios)
  * `nota_credito_id` (int, Foreign Key compuesta con `tenant_id` -> Notas_Credito_Devoluciones, Nullable)
  * `numero` (int)
  * `motivo` (text)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `supervisor_id` (int, Foreign Key compuesta con `tenant_id` -> Users) -- Rol Shield `supervisor`
  * `timestamps` (timestamp)
  * Único `(tenant_id, talonario_id, numero)`
* **Ventas**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `codigo` (varchar) -- Único `(tenant_id, codigo)`. Visible en el papel. Formato `VNT-` más el número. Mínimo 6 dígitos (`VNT-000001`). Al pasar de 999999 sigue (`VNT-1000000`): no se corta ni se reinicia
  * `sucursal_id` (int, Foreign Key compuesta con `tenant_id` -> Sucursales) -- Quién cobró
  * `almacen_id` (int, Foreign Key compuesta con `tenant_id` -> Almacenes) -- De qué montón salió la mercadería
  * `talonario_id` (int, Foreign Key compuesta con `tenant_id` -> Talonarios, Nullable) -- Obligatorio si es factura manual
  * `cliente_id` (int, Foreign Key compuesta con `tenant_id` -> Clientes, Nullable) -- Obligatorio si `condicion = 'credito'` o `estado` es `reserva` o `vencida`
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `condicion` (enum: 'contado', 'credito') -- Default: 'contado'. El fiado es crédito. La seña es `estado = 'reserva'`, no otra condición
  * `estado` (enum: 'pendiente', 'completada', 'anulada', 'reserva', 'vencida') -- Default: 'pendiente'
  * `vence_en` (timestamp, Nullable) -- Obligatorio si `estado` es `reserva` o `vencida`. Al crearla puede ser antes o después de las 48 horas
  * `tipo_comprobante` (enum: 'nota_venta', 'factura_manual')
  * `numero_comprobante` (varchar, Nullable) -- Único `(tenant_id, sucursal_id, tipo_comprobante, numero_comprobante)`
  * `subtotal` (decimal 10,2)
  * `tipo_descuento` (enum: 'porcentaje', 'importe', Nullable) -- Descuento del ticket, no de una línea
  * `valor_descuento` (decimal 10,2, Default: 0.00) -- El 10 de un 10 %, o el monto si es importe
  * `descuento` (decimal 10,2, Default: 0.00) -- Plata total restada: líneas + ticket
  * `supervisor_descuento_id` (int, Foreign Key compuesta con `tenant_id` -> Users, Nullable) -- Obligatorio si el descuento del ticket pasa el tope
  * `total` (decimal 10,2)
  * `notes_internas` (text, Nullable)
  * `timestamps` (timestamp)
  * Foreign key `(tenant_id, sucursal_id, almacen_id)` -> `sucursal_almacenes`
  * CHECK: si `tipo_comprobante = 'factura_manual'`, entonces `cliente_id`, `numero_comprobante` y `talonario_id` no son nulos. El número cae entre `numero_desde` y `numero_hasta` del talonario; lo comprueba la aplicación. `descuento` no supera `subtotal`. Si `tipo_descuento = 'porcentaje'`, `valor_descuento` no supera 100.
* **Venta_Detalles**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `venta_id` (int, Foreign Key compuesta con `tenant_id` -> Ventas, On Delete: Cascade)
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `unidad_medida_id` (int, Foreign Key compuesta con `tenant_id` -> Unidades_Medida) -- La unidad tipeada. Todavía no hay tabla de ventas: esta es la forma cuando se construya
  * `factor_a_base` (decimal 18,6) -- Congelado al vender
  * `cantidad` (decimal 14,4) -- Lo tipeado en esa unidad
  * `cantidad_base` (decimal 14,4) -- Lo que sale del stock y lo que reserva la seña
  * `precio_unitario` (decimal 10,2) -- Precio de una unidad base, menor o mayor, antes del descuento
  * `precio_costo_snapshot` (decimal 14,4) -- PPP de una unidad base, congelado para utilidad inalterable
  * `tipo_descuento` (enum: 'porcentaje', 'importe', Nullable)
  * `valor_descuento` (decimal 10,2, Default: 0.00) -- El 10 de un 10 %, o el monto en la moneda de la tienda si es importe
  * `descuento_aplicado` (decimal 10,2, Default: 0.00) -- Plata restada en la línea
  * `supervisor_descuento_id` (int, Foreign Key compuesta con `tenant_id` -> Users, Nullable) -- Obligatorio si el descuento pasa el tope del cajero
  * `total_linea` (decimal 10,2) -- `cantidad_base * precio_unitario` menos `descuento_aplicado`
  * `sin_devolucion_comercial` (boolean, Default: false) -- Copia al vender. Un cambio posterior de la ficha no la pisa
  * `motivo_sin_devolucion` (varchar, Nullable) -- Nombre del producto o del rubro que originó la exclusión
  * `timestamps` (timestamp)
  * CHECK: `descuento_aplicado` no supera `precio_unitario * cantidad_base`. Si `tipo_descuento = 'porcentaje'`, `valor_descuento` no supera 100.
* **Venta_Impuestos (Snapshots Fiscales Transaccionales)**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `venta_id` (int, Foreign Key compuesta con `tenant_id` -> Ventas, On Delete: Cascade)
  * `nombre_impuesto_snapshot` (varchar) -- Copiado de `impuestos.nombre`
  * `porcentaje_aplicado_snapshot` (decimal 5,2) -- Copiado de `impuestos.porcentaje` en esa venta
  * `monto_calculado` (decimal 10,2)
  * `timestamps` (timestamp)
* **Pagos** (Solo plata de la caja. No dice a qué venta se aplica)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `cliente_id` (int, Foreign Key compuesta con `tenant_id` -> Clientes, Nullable) -- Obligatorio si queda saldo a favor o si cobra un fiado o una seña
  * `caja_sesion_id` (int, Foreign Key compuesta con `tenant_id` -> Cajas_Sesiones, Nullable) -- Obligatoria si el método es efectivo: tiene que haber una caja abierta
  * `sentido` (enum: 'cobro', 'reembolso') -- Default: 'cobro'
  * `metodo_pago` (varchar) -- En v1: `efectivo`, `qr_externo`, `voucher_tarjeta`, `transferencia`. Sin pasarela. Handlers en `App\Ventas\Pagos`
  * `monto` (decimal 10,2) -- Siempre positivo. El sentido dice si entra o sale
  * `comprobante_archivo_path` (varchar, Nullable) -- Ruta relativa en disco local. El tenancy separa por tenant; S3 después. Solo PDF o imagen (jpg, jpeg, png, webp), máx. 5 MB. En `negocio_configuracion`: `comprobante_obligatorio_qr_externo`, `comprobante_obligatorio_transferencia` y `comprobante_obligatorio_voucher_tarjeta` (boolean, default false) dicen si ese medio exige el archivo. Efectivo no acepta adjunto.
  * `numero_referencia_banco` (varchar, Nullable)
  * `qr_id_transaccion_banco` (varchar, Nullable)
  * `estado_pago` (enum: 'pendiente', 'aprobado', 'expirado') -- Default: 'aprobado'. Solo `aprobado` cuenta para el saldo
  * `fecha_pago` (timestamp)
  * `timestamps` (timestamp)
* **Pago_Aplicaciones** (A qué documento se aplicó la plata. Se crea después de ventas, pagos y notas)
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `pago_id` (int, Foreign Key compuesta con `tenant_id` -> Pagos)
  * `venta_id` (int, Foreign Key compuesta con `tenant_id` -> Ventas, Nullable)
  * `nota_credito_id` (int, Foreign Key compuesta con `tenant_id` -> Notas_Credito_Devoluciones, Nullable)
  * `aplicacion_origen_id` (int, Foreign Key compuesta con `tenant_id` -> Pago_Aplicaciones, Nullable) -- La fila de saldo a favor que este uso consume
  * `monto` (decimal 10,2) -- CHECK `monto > 0`
  * `timestamps` (timestamp)
  * Un cobro original tiene `aplicacion_origen_id` nulo y apunta a una venta o a ninguna (saldo a favor). En devoluciones de fase 7, la aplicación del nuevo reembolso apunta tanto a `venta_id` como a `nota_credito_id`, y `aplicacion_origen_id` identifica el cobro que compensa. Este doble vínculo conserva los historiales y el cálculo de cobrado de la venta. Un saldo restituido tiene `venta_id` nulo, `nota_credito_id` informado y `es_saldo_favor = true`, ligado al pago original sin crear otro movimiento de caja.
  * La asignación inicial de cada cobro coincide con `pagos.monto`. Cada reembolso nuevo se aplica por su importe a la venta y a su nota; el vínculo al cobro original no duplica ese pago. Las transferencias de saldo reutilizan el cobro original y no se suman como entradas nuevas de dinero.
  * Usar un adelanto, o soltar la seña de una reserva vencida, inserta otra fila con `aplicacion_origen_id` y el mismo `pago_id`. No crea un pago nuevo. Si pasa a saldo a favor, `venta_id` va nulo. La suma usada no supera el monto de la fila de origen.
  * Todas las ventas de un mismo pago son del mismo `cliente_id`. Lo comprueba la aplicación.
  * El por cobrar usa el total vigente (última corrección o total original menos notas de devolución) y los cobros aprobados, descontando reembolsos y saldo restituido. El contado solo pasa a `completada` cuando ese saldo es cero. El crédito puede completarse con saldo. La reserva no descuenta `cantidad_actual`: suma `cantidad_reservada`. Al vencer, esa suma se revierte y la venta no sigue como por cobrar.

### 6. Módulo de Notas de Crédito y Cambios de Mostrador
* **Notas_Credito_Devoluciones**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `venta_id` (int, Foreign Key compuesta con `tenant_id` -> Ventas) -- La sucursal y almacén son los de esa venta
  * `caja_sesion_id` (int, Foreign Key compuesta con `tenant_id` -> Cajas_Sesiones, Nullable) -- Turno donde se compensa el dinero
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `motivo` (text)
  * `motivo_excepcion` (text, Nullable) -- Excepción al plazo autorizada por el mismo operador con permiso específico
  * `resolucion` (enum: 'reembolso', 'saldo_favor')
  * `total`, `deuda_cancelada`, `importe_resuelto` (decimal 10,2) -- Valor devuelto = reducción de deuda + importe resuelto
  * `revision` (varchar 64) -- Huella de la revisión confirmada; única por tenant
  * `timestamps` (timestamp)
* **Nota_Credito_Detalles**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string)
  * `nota_credito_id` (int, Foreign Key compuesta con `tenant_id` -> Notas_Credito_Devoluciones)
  * `venta_detalle_id` (int, Foreign Key compuesta con `tenant_id` -> Venta_Detalles, Nullable) -- Nulo cuando la línea procede de una corrección
  * `linea_indice` (entero no negativo) -- Posición de la línea vigente; única por tenant + nota. Tras una devolución no se permite alterar las líneas mediante correcciones
  * `producto_variante_id` (int, Foreign Key compuesta con `tenant_id` -> Productos_Variantes)
  * `cantidad`, `cantidad_base` (decimal 14,4)
  * `importe` (decimal 10,2)
  * `linea_snapshot` (json) -- Línea vigente: unidad y factor, cantidades, precio, descuentos, costo congelado, identificación e importe asignado
  * `timestamps` (timestamp)
  * La suma devuelta por venta + linea_indice no supera la cantidad vigente vendida. Lo valida el servicio con la venta bloqueada. Si había deuda, se reduce primero. El reintegro solo corresponde a lo cobrado y aún no compensado. En un cambio, venta_reemplazo_id vincula la nota de reemplazo y el campo resolucion queda vacío cuando todo el valor devuelto se aplica al reemplazo. Las aplicaciones marcadas es_compensacion_cambio trasladan ese importe desde los cobros originales sin crear otro movimiento de caja. cliente_saldo_id permite asignar un saldo a una persona identificada cuando el cobro original era de consumidor final.

### 7. Módulo de Caja y Sesiones de Turno
* **Cajas_Sesiones**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `sucursal_id` (int, Foreign Key compuesta con `tenant_id` -> Sucursales) -- La caja sigue siendo de la sucursal, no del negocio entero
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `estado_sesion` (enum: 'abierta', 'cerrada') -- Default: 'abierta'
  * `fecha_apertura` (timestamp)
  * `fecha_cierre` (timestamp, Nullable)
  * `monto_apertura` (decimal 10,2)
  * `monto_cierre_teorico` (decimal 10,2, Nullable)
  * `monto_cierre_real` (decimal 10,2, Nullable)
  * `diferencia` (decimal 10,2, Default: 0.00)
  * `justificacion_diferencia` (text, Nullable)
  * `supervisor_id` (int, Foreign Key compuesta con `tenant_id` -> Users, Nullable) -- Rol Shield `supervisor` si autoriza el desfase
  * `timestamps` (timestamp)
  * Una sola fila `abierta` por `(tenant_id, sucursal_id, user_id)`
  * CHECK: si está `cerrada`, `fecha_cierre` no es nula. Si `diferencia` no es cero, `justificacion_diferencia` no es nula

### 8. Módulo de Caja Chica y Gastos Menores
* **Categorias_Gastos**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `nombre` (varchar) -- Único `(tenant_id, nombre)`
  * `descripcion` (text, Nullable)
  * `is_active` (boolean, Default: true)
  * `timestamps` (timestamp)
* **Caja_Chica_Movimientos**
  * `id` (int, Primary Key, Increment)
  * `tenant_id` (string) -- Único junto con `id`
  * `sucursal_id` (int, Foreign Key compuesta con `tenant_id` -> Sucursales)
  * `caja_sesion_id` (int, Foreign Key compuesta con `tenant_id` -> Cajas_Sesiones)
  * `categoria_gasto_id` (int, Foreign Key compuesta con `tenant_id` -> Categorias_Gastos, Nullable)
  * `user_id` (int, Foreign Key compuesta con `tenant_id` -> Users)
  * `solicitado_por_trabajador_id` (int, Foreign Key compuesta con `tenant_id` -> Trabajadores, Nullable)
  * `tipo_movimiento` (enum: 'egreso_gasto', 'devolucion_sobrante')
  * `movimiento_origen_id` (int, Foreign Key compuesta con `tenant_id` -> Caja_Chica_Movimientos, Nullable)
  * `estado_rendicion` (enum: 'pendiente_comprobante', 'rendido_completo')
  * `monto` (decimal 10,2)
  * `concepto` (varchar)
  * `tipo_comprobante` (enum: 'recibo_interno', 'factura', 'sin_comprobante')
  * `numero_comprobante` (varchar, Nullable)
  * `comprobante_archivo_path` (varchar, Nullable)
  * `timestamps` (timestamp)

---

## PARTE 3: CASOS DE USO

Una venta sale de un solo almacén. Si el ticket necesita dos depósitos, son dos ventas. El cliente no tiene moneda: paga en la de la tienda.

* **Contado en mostrador.** Caja abierta. La venta lleva `codigo` `VNT-`, correlativo del negocio, que no se reinicia y no se corta al pasar de 6 dígitos. Se cobra el precio menor o mayor de una unidad base, con descuento de porcentaje o de importe dentro del tope. La plata de la línea es la cantidad en unidad base por ese precio. El pago entra a la caja de hoy, del stock sale esa cantidad base y el costo promedio de una unidad base queda pegado en la línea. Si el promedio es mayor que lo que va a pagar por unidad base, hay alerta y la venta puede seguir.
* **Lector y búsqueda.** El código de barras abre una sola variante. Sin etiqueta, se busca por SKU o por los atributos de la categoría, dentro de ese negocio y de ese rubro. Un rodamiento de 20 × 47 × 14 se filtra por igualdad: interno `20`, externo `47` y ancho `14`, y el índice GIN resuelve esa búsqueda. Un filtro de aceite se busca por su rosca o por su altura, con las claves de esa categoría. La unidad no se escribe en el filtro. Si la muestra está gastada, el rango (de 19.5 a 20.5) se compara como número sobre los productos ya acotados de esa categoría.
* **Seña.** Cliente obligatorio. Se aparta stock, no sale del estante, y la plata entra hoy. Dura 48 horas salvo que al crearla pongan menos o más. Si vuelve y paga el resto, el stock sale y el cobro entra en la caja de ese día. Si vence, el stock se libera y la seña queda a su favor. Si pide la plata de vuelta, es un reembolso en la caja de hoy, con supervisor.
* **Fiado.** El stock sale hoy y la caja no se mueve. Otro día paga, con la caja de ese día abierta, y el saldo baja. Si devuelve todo y no había pagado, vuelve el stock y no sale plata. Si ya había pagado una parte, se reembolsa esa parte y el resto de la deuda se cancela.
* **Adelanto sin pieza, o un pago de varias deudas.** Un solo movimiento de caja. Se aplica después a una venta, o se reparte en varias, sin volver a sumar en la caja.
* **Entrada de mercadería.** La compra guarda la unidad tipeada, la cantidad tipeada y el factor congelado. Al stock entra la cantidad en la unidad base. El costo que alimenta el promedio es el de una unidad base. Si fue en dólares, guarda también el tipo de cambio de ese día y convierte a la moneda de la tienda. Antes de guardar, la pantalla pide confirmar el costo por unidad base. El precio de venta no cambia solo.
* **Costo mal tipeado.** Las ventas ya cerradas siguen con el costo que tenían. Un supervisor corrige el promedio de lo que queda en el estante.
* **Dólar.** La tienda que no importa no lo ve. La que importa pone el tipo de cambio. Los productos marcados en dólares pasan a la moneda de la tienda en un trabajo por lotes, con una sola confirmación. Lo ya vendido no se toca.
* **Ajuste y conteo.** Motivo y supervisor. Cambia cuántas piezas hay, no el precio. El conteo compara contra el stock del cierre e incluye las señas. No mueve la caja.
* **Traslado.** Solo si son dos montones distintos. Un almacén compartido por dos sucursales no se traslada: las dos venden de la misma fila.
* **Devolución del mismo día.** Supervisor, la pieza vuelve al kardex con el costo de esa venta, y la plata sale de la caja abierta.
* **Factura manual.** Pospuesta el 11 de noviembre de 2026. La venta vigente es la nota de venta y el talonario de papel se lleva afuera, adjuntando la nota a la libreta. Cuando se retome: cliente, número y talonario; si se anula, queda la observación del papel.
* **Caja.** Una sesión abierta por cajero y sucursal. No cierra si hay un vale sin comprobante. Si el efectivo no cuadra, el texto de la diferencia es obligatorio.
* **Dos negocios.** El mismo SKU, el mismo NIT y el mismo número de factura pueden existir en los dos. Una venta no puede usar el producto del otro.
