# Fase 6 en curso

Actualizado el 7 de noviembre de 2026.

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

## Decisión comercial de reservas

En Configuración, permite_reembolso_senas controla las reservas nuevas. Su valor inicial es habilitado, también para las reservas anteriores a esta ampliación. Cada reserva copia la condición en sena_reembolsable. Cambiar luego la configuración mantiene las condiciones de las reservas existentes.

Al seleccionar un cliente en Mostrador se muestra su saldo a favor disponible. Se puede aplicar un importe a la compra y completar con un cobro nuevo. La aplicación reutiliza el pago original mediante pago_aplicaciones, marcado como es_saldo_favor. Caja contabiliza únicamente el cobro nuevo y el detalle identifica el saldo aplicado.

Las señas reembolsables de reservas vencidas o canceladas ofrecen Reembolsar seña desde su detalle. Exige Reembolsar:Sena, permiso de consulta, una caja abierta de hoy en la sucursal, importe disponible y motivo. Puede devolverse una parte o todo lo disponible por los métodos originales. El movimiento guarda registrado_por_id y motivo en pagos. Un reintento con un saldo desactualizado se rechaza.

Una seña sin permiso comercial de reembolso conserva el saldo para compras futuras. La retención definitiva por incumplimiento sigue pendiente de definición y desarrollo.

Si se corrige o anula una compra pagada con saldo a favor, esa parte se restituye al saldo del cliente mediante otra aplicación. El reembolso monetario corresponde a los cobros nuevos. reserva_saldo_origen_id conserva el vínculo con la reserva inicial incluso después de esa restitución.

Seña al crear y saldo completo al retirar. El flujo actual no ofrece abonos sucesivos a una reserva. El pago a crédito tiene su propio flujo de abonos.

La fecha de retiro debe ser futura. La entrega exige una reserva vigente, los permisos de crear y consultar ventas y una caja abierta de hoy en la sucursal. El saldo se vuelve a comprobar al confirmar.

La fecha inicial se calcula con horas_reserva y se propone a las 23:59 de ese día. El usuario puede cambiar tanto el día como la hora antes de confirmar la reserva.

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

Estos cambios están relacionados con la operación de ventas. La fase 7 de devoluciones físicas sigue pendiente.

## Pendientes para cerrar la fase

* Cambiar el vencimiento de una reserva existente mediante permiso y registro del cambio.
* Ampliar los métodos del cobro del saldo y de los abonos cuando se defina ese alcance.
* Completar las pruebas de integración entre reservas, saldo a favor y pagos del alcance actual, y ejecutar la suite completa.

## Mejoras pospuestas para el futuro

Por decisión del 7 de noviembre de 2026, estos puntos quedan fuera de los requisitos para cerrar la fase 6, sin asignarles todavía una fase futura:

* Registrar adelantos de clientes sin elegir productos. Por ahora, las señas se reciben al crear una reserva con productos; se mantiene el uso del saldo a favor ya implementado.
* Aplicar un mismo pago a varias ventas pendientes. Por ahora, los abonos se registran por separado en cada venta. Las pruebas de distribución de un pago entre varias ventas se realizarán cuando se implemente esa función.

## Verificación realizada

Pruebas enfocadas cubren creación desde Mostrador, stock físico y reservado, entrega con pago del saldo, vencimiento y reintentos. La ampliación de cancelación pasó 12 pruebas con 235 comprobaciones, incluyendo permisos, motivo, pagos pendientes, cancelación con caja cerrada, conservación de otra reserva, rechazo de entrega posterior, aislamiento entre negocios y reembolso según la condición guardada. También se verificaron los abonos a crédito y la venta de contado existente. La compilación de estilos terminó correctamente. La suite completa del proyecto queda por ejecutar antes de cerrar la fase.
