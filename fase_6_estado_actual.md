# Fase 6 cerrada con el alcance acordado

Actualizado el 9 de noviembre de 2026.

El usuario ejecutó la suite completa con php artisan test --compact y compartió el resultado: 163 pruebas aprobadas, 1.419 comprobaciones y 337,40 segundos. Se cierra la fase con las funciones y límites descritos aquí; las mejoras futuras continúan pendientes.

Este archivo registra el estado real de la fase. El alcance general se mantiene en fases_erp_mostrador_f4aaf7c6.plan.md y las condiciones del proyecto en esstructuraybasecondiciones.md.

## Funciones disponibles

* Mostrador ofrece Contado, Crédito y Reserva.
* Crédito requiere cliente, descuenta stock al registrar y permite dejar deuda con o sin un pago inicial. Los abonos posteriores en efectivo se registran desde Mis ventas → Ver detalle → Registrar abono.
* Reserva requiere cliente y una seña positiva menor al total. Guarda vence_en, con plazo inicial de horas_reserva, configurable por negocio y ajustable al crearla. Aumenta cantidad_reservada y mantiene la cantidad física hasta la entrega.
* Desde el detalle de una reserva vigente se puede cobrar el saldo completo en efectivo y entregar. Se registra el cobro en la caja de hoy de esa sucursal, se libera la cantidad reservada y se genera la salida de inventario. El costo se congela al entregar.
* GestionarReservas::procesarVencidas() procesa las reservas vencidas del negocio actual. Se invoca al abrir Mostrador, consultar productos o ventas y antes de registrar una venta, reservar o entregar. Libera unidades y registra la seña a favor mediante una aplicación vinculada al pago original. Los reintentos mantienen una sola liberación y una sola aplicación.
* Mis ventas filtra por fecha, condición y estado. El detalle muestra la vigencia de la reserva.
* Cancelar reserva está disponible en el detalle de una reserva vigente, desde Mostrador y los historiales de caja. Usa la autorización de anulación (View:Venta y Update:Venta), exige motivo y registra usuario y fecha en los campos de anulación existentes. Queda en estado Anulada y conserva sus productos y cobros en el historial.
* La cancelación libera únicamente las unidades apartadas de esa reserva, sin cambiar stock físico, costo ni kardex. La seña pasa a saldo a favor con el pago original; cancelar no requiere caja abierta ni genera otro cobro o reembolso. Desde el detalle puede registrarse después el reembolso con Reembolsar:Sena, caja de hoy y la condición comercial guardada. Los reintentos y los intentos posteriores de entrega no duplican la liberación.
* Ampliar reserva permite cambiar el vencimiento de una reserva vigente a una fecha posterior, con motivo obligatorio. Exige View:Venta y Ampliar:Reserva, permiso independiente asignable a roles o directamente a usuarios. Registra fecha anterior, nueva, usuario, momento y motivo en ampliaciones_reserva, consultable desde el detalle. Si el vencimiento cambió durante la revisión se rechaza y se debe volver a abrir el formulario. Las reservas vencidas, canceladas o entregadas no se amplían.

## Decisión comercial de reservas

En Configuración, permite_reembolso_senas controla las reservas nuevas. Su valor inicial es habilitado, también para las reservas anteriores a esta ampliación. Cada reserva copia la condición en sena_reembolsable. Cambiar luego la configuración mantiene las condiciones de las reservas existentes.

Al seleccionar un cliente en Mostrador se muestra su saldo a favor disponible. Se puede aplicar un importe a la compra y completar con un cobro nuevo. La aplicación reutiliza el pago original mediante pago_aplicaciones, marcado como es_saldo_favor. Caja contabiliza únicamente el cobro nuevo y el detalle identifica el saldo aplicado.

Las señas reembolsables de reservas vencidas o canceladas ofrecen Reembolsar seña desde su detalle. Exige Reembolsar:Sena, permiso de consulta, una caja abierta de hoy en la sucursal, importe disponible y motivo. Puede devolverse una parte o todo lo disponible por los métodos originales. El movimiento guarda registrado_por_id y motivo en pagos. Un reintento con un saldo desactualizado se rechaza.

Una seña sin permiso comercial de reembolso conserva el saldo para compras futuras. La retención definitiva por incumplimiento sigue pendiente de definición y desarrollo.

Si se corrige o anula una compra pagada con saldo a favor, esa parte se restituye al saldo del cliente mediante otra aplicación. El reembolso monetario corresponde a los cobros nuevos. reserva_saldo_origen_id conserva el vínculo con la reserva inicial incluso después de esa restitución.

Seña al crear y saldo completo al retirar. El flujo actual no ofrece abonos sucesivos a una reserva. El pago a crédito tiene su propio flujo de abonos.

Cuando el importe aplicado alcanza o supera el total, la reserva no se confirma y el aviso junto al botón indica: «Si el cliente paga todo y retira los productos ahora, elegí Contado. Para reservar, ingresá una seña menor al total». No se muestra «Completo» como si la reserva estuviera lista. Una reserva pagada íntegramente con retiro posterior todavía no forma parte del flujo.

La fecha de retiro debe ser futura. La entrega exige una reserva vigente, los permisos de crear y consultar ventas y una caja abierta de hoy en la sucursal. El saldo se vuelve a comprobar al confirmar.

La fecha inicial se calcula con horas_reserva y se propone a las 23:59 de ese día. El usuario puede cambiar tanto el día como la hora antes de confirmar la reserva.

Al ampliar una reserva, el formulario propone el día siguiente al vencimiento actual a las 23:59. El usuario puede ajustar la fecha y la hora antes de confirmar.

El permiso Ampliar:Reserva se incorpora al rol supervisor por defecto. La autorización usa el permiso y View:Venta; también funciona con una asignación directa a un usuario. La pantalla general para administrar permisos individuales queda para un desarrollo posterior.

## Arquitectura de reservas y evolución futura

Actualmente la reserva comercial se guarda en ventas con estado Reserva, reutilizando sus detalles y pagos. Al entregar, el mismo registro pasa a Completada. Las unidades apartadas se controlan mediante cantidad_reservada, sin descontar la existencia física hasta la entrega.

Se mantiene este diseño para el flujo actual de seña y pago completo del saldo al retirar. No se requiere una tabla comercial independiente de reservas para este alcance.

Si se incorporan entregas parciales, asignación por lotes o varios almacenes para un mismo pedido, evaluar registros específicos de inventario reservado vinculados a los detalles del documento. Separar esos registros de inventario no implica necesariamente separar la reserva comercial de ventas. Esta evaluación queda para una ampliación futura, sin cambios de esquema por ahora.

Como referencia, ERPNext crea registros de Stock Reservation Entry asociados a las líneas del pedido comercial. Documentación oficial: https://docs.frappe.io/erpnext/stock-reservation.

## Operación local

El Mostrador permite navegar, consultar ventas y preparar el carrito sin caja abierta. Para confirmar una venta o reserva se necesita la caja de hoy en la sucursal seleccionada. El aviso enlaza a Caja en otra pestaña y permite actualizar el estado conservando el carrito. Las sesiones abiertas de días anteriores no cuentan como caja vigente.

Los vencimientos se procesan cuando el negocio vuelve a operar. Una reserva cuyo plazo pasó durante la inactividad se libera al siguiente acceso operativo. La comprobación anterior a confirmar evita usar reservas vencidas aunque la pantalla haya quedado abierta. El procesamiento se confirma antes de la transacción de la nueva operación, de modo que un intento fallido conserva la liberación.

El comando php artisan ventas:vencer-reservas queda disponible para mantenimiento manual de todos los negocios. No hay una tarea programada cada minuto para reservas. Este flujo permite aprovechar la hibernación del alojamiento y no requiere schedule:work para liberar reservas al operar.

## Cambios complementarios realizados

* Anular notas de venta completadas, con motivo, usuario, fecha y reembolso vinculado a los cobros originales. La venta sigue en el historial.
* Corregir productos o cantidades mediante un documento vinculado e identificación de cambios concurrentes. Vista original y vista actual disponibles en el detalle.
* Permiso específico Corregir:Venta. El nombre del rol no decide la autorización.
* Resumen simplificado del detalle, aviso de reembolso y movimientos con importe y sesión.
* Retiro del formulario independiente de abonos por código en Mostrador.

Estos cambios están relacionados con la operación de ventas. Las devoluciones físicas quedaron en la fase 7, cerrada el 11 de noviembre de 2026. Su estado vigente, incluidas las exclusiones por categoría y producto, está en esstructuraybasecondiciones.md.

## Límites del alcance cerrado

* El cobro del saldo al entregar y los abonos posteriores siguen en efectivo. Ampliar sus métodos de pago queda pendiente de definir como mejora posterior.
* Los adelantos sin productos y pagos para varias ventas mantienen el estado pendiente descrito abajo.

## Ampliación posterior: vuelto o cambio en efectivo

Implementado el 9 de noviembre de 2026. Cada cobro en efectivo distingue el importe aplicado del efectivo recibido. El segundo campo es opcional en pantalla: vacío significa pago exacto. El servidor calcula el cambio y exige recibido mayor o igual al importe aplicado. En pagos combinados se ingresa el importe que corresponde a cada método; solo la línea de efectivo admite recibido y cambio.

pagos incorpora efectivo_recibido y cambio_entregado, ambos decimales opcionales. monto conserva el importe aplicado. Los pagos anteriores quedan con esos datos vacíos, sin inventar información histórica. Los nuevos cobros en efectivo guardan ambos valores, incluso en pago exacto. PostgreSQL comprueba que recibido menos cambio coincida con monto y que estos datos correspondan a cobros en efectivo.

Funciona al crear ventas de contado, señas y pagos iniciales a crédito desde Mostrador, así como en Registrar abono y Cobrar saldo y entregar. El detalle muestra los valores guardados junto al pago original; al reutilizar saldo a favor no se presentan como si se hubiera recibido efectivo nuevamente. El cambio no crea un reembolso comercial ni se suma como ingreso de caja. Anular devuelve únicamente lo cobrado y las correcciones conservan sus reglas existentes. El formulario de corrección no incorpora por ahora un campo de efectivo recibido.

QR, tarjeta y transferencia no generan vuelto. Los excedentes electrónicos no se convierten automáticamente en una salida del cajón. Los importes y aplicaciones mantienen sus validaciones actuales. La emisión de comprobantes impresos sigue fuera de esta ampliación; los valores quedan disponibles para usarlos cuando se implemente.

Verificación del vuelto: 10 pruebas con 73 comprobaciones para los casos nuevos y validaciones; luego 12 pruebas con 209 comprobaciones incluyendo las acciones reales de entrega y abono, vencimientos y saldo a favor. Los conteos se superponen. Compilación y formato correctos, migración local aplicada. El resultado de 163 pruebas de cierre que figura al inicio es anterior a esta ampliación; no se repitió la suite completa tras agregar vuelto.

Prueba manual en la interfaz local: se abrió la sesión #7 de God en Alto San Pedro con fondo cero y se registraron VNT-000010 (total 52, efectivo recibido 100, cambio 48) y VNT-000011 (total 52, QR 30, efectivo aplicado 22, recibido 100, cambio 78). Se verificaron el aviso de cambio, el detalle guardado del pago combinado y el resumen de caja: cobros 104, efectivo esperado 74 y QR 30. Estas dos ventas son datos de prueba locales y la sesión se dejó abierta.

## Mejoras pospuestas para el futuro

El vuelto/cambio solicitado el 8 de noviembre se implementó como ampliación posterior al cierre de fase, con el alcance descrito arriba.

Por decisión del 7 de noviembre de 2026, estos puntos quedan fuera de los requisitos para cerrar la fase 6, sin asignarles todavía una fase futura:

* Registrar adelantos de clientes sin elegir productos. Por ahora, las señas se reciben al crear una reserva con productos; se mantiene el uso del saldo a favor ya implementado.
* Aplicar un mismo pago a varias ventas pendientes. Por ahora, los abonos se registran por separado en cada venta. Las pruebas de distribución de un pago entre varias ventas se realizarán cuando se implemente esa función.

## Verificación realizada

Pruebas enfocadas cubren creación desde Mostrador, stock físico y reservado, entrega con pago del saldo, vencimiento y reintentos. La ampliación de cancelación pasó 12 pruebas con 235 comprobaciones, incluyendo permisos, motivo, pagos pendientes, cancelación con caja cerrada, conservación de otra reserva, rechazo de entrega posterior, aislamiento entre negocios y reembolso según la condición guardada. También se verificaron los abonos a crédito y la venta de contado existente. La compilación de estilos terminó correctamente. La suite completa del proyecto fue ejecutada por el usuario el 9 de noviembre de 2026, con el resultado de cierre registrado al inicio de este documento.

El 8 de noviembre se verificó además la navegación sin caja vigente y la conservación del carrito (3 pruebas, 23 comprobaciones), la ampliación con permiso individual junto con detalle y cancelación (4 pruebas, 117 comprobaciones), y la hora inicial de la ampliación (prueba enfocada, 24 comprobaciones). Las pruebas de creación de reservas también comprueban las 23:59. Estas ejecuciones son parciales y sus conteos se superponen; no equivalen a la suite completa. La migración local del historial ampliaciones_reserva y del permiso Ampliar:Reserva quedó aplicada.
