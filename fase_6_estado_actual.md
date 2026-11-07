# Fase 6 en curso

Actualizado el 6 de noviembre de 2026.

Este archivo registra el estado real de la fase. El alcance general se mantiene en fases_erp_mostrador_f4aaf7c6.plan.md y las condiciones del proyecto en esstructuraybasecondiciones.md.

## Funciones disponibles

* Mostrador ofrece Contado, Crédito y Reserva.
* Crédito requiere cliente, descuenta stock al registrar y permite dejar deuda con o sin un pago inicial. Los abonos posteriores en efectivo se registran desde Mis ventas → Ver detalle → Registrar abono.
* Reserva requiere cliente y una seña positiva menor al total. Guarda vence_en, con plazo inicial de horas_reserva, configurable por negocio y ajustable al crearla. Aumenta cantidad_reservada y mantiene la cantidad física hasta la entrega.
* Desde el detalle de una reserva vigente se puede cobrar el saldo completo en efectivo y entregar. Se registra el cobro en la caja de hoy de esa sucursal, se libera la cantidad reservada y se genera la salida de inventario. El costo se congela al entregar.
* GestionarReservas::procesarVencidas() procesa las reservas vencidas del negocio actual. Se invoca al abrir Mostrador, consultar productos o ventas y antes de registrar una venta, reservar o entregar. Libera unidades y registra la seña a favor mediante una aplicación vinculada al pago original. Los reintentos mantienen una sola liberación y una sola aplicación.
* Mis ventas filtra por fecha, condición y estado. El detalle muestra la vigencia de la reserva.

## Decisión comercial de reservas

Seña al crear y saldo completo al retirar. El flujo actual no ofrece abonos sucesivos a una reserva. El pago a crédito tiene su propio flujo de abonos.

La fecha de retiro debe ser futura. La entrega exige una reserva vigente, los permisos de crear y consultar ventas y una caja abierta de hoy en la sucursal. El saldo se vuelve a comprobar al confirmar.

La fecha inicial se calcula con horas_reserva y se propone a las 23:59 de ese día. El usuario puede cambiar tanto el día como la hora antes de confirmar la reserva.

## Operación local

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

* Registrar adelantos de clientes y consultar su saldo a favor.
* Aplicar a una venta el saldo a favor ya registrado, incluida una seña vencida, sin duplicar cobros.
* Distribuir un pago entre varias ventas.
* Cancelar reservas con un flujo explícito para stock y seña.
* Cambiar el vencimiento de una reserva existente mediante permiso y registro del cambio.
* Ampliar los métodos del cobro del saldo y de los abonos cuando se defina ese alcance.
* Completar las pruebas de integración entre reservas, saldo a favor y pagos distribuidos.

## Verificación realizada

Pruebas enfocadas cubren creación desde Mostrador, stock físico y reservado, entrega con pago del saldo, vencimiento y reintentos. También se verificaron los abonos a crédito y la venta de contado existente. La compilación de estilos terminó correctamente. La suite completa del proyecto queda por ejecutar antes de cerrar la fase.
