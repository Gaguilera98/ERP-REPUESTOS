# Fase 10. Caja chica

**Estado:** fase cerrada. La migración `2026_11_12_213600_create_caja_chica_tables` está aplicada en el pool local del tenant `god`.

La entrega y rendición del vale se verificaron en el ERP local con BOB 100 de apertura, BOB 50 entregados y BOB 15 devueltos. El saldo quedó en BOB 65. La última ejecución de `CajaChicaTest` pasó con 11 pruebas y 73 aserciones, incluyendo el arqueo de entrega y la autorización sobre la caja de origen. La suite completa queda pendiente antes de desplegar.

## Fondos y configuración

- **Caja chica independiente.** Fondo predeterminado para gastos. En la primera versión, un fondo por sucursal, con un responsable asignado, apertura, saldo inicial, reposiciones, historial y arqueo propios.
- **Caja del vendedor.** El negocio puede habilitar gastos y vales desde los cajones de venta. Esta opción empieza desactivada. Activarla habilita la función y los permisos determinan quién puede usarla.
- El movimiento se registra en el fondo o turno del que salió físicamente el dinero. **Cajas del día** consolida y supervisa turnos, sin un saldo operativo propio.
- Si se desactiva el uso de cajones de venta, se impiden nuevas salidas desde esos cajones. Las rendiciones y revisiones pendientes siguen disponibles con sus permisos.

## Permisos y alcance

Los permisos se pueden asignar por rol o directamente a un usuario. El servidor comprueba el permiso, el negocio, la sucursal y el fondo o turno en cada operación.

- **Registrar gastos en mi caja.** Permite registrar un gasto o entregar un vale desde el propio turno abierto.
- **Registrar gastos en otras cajas.** Permite elegir un vendedor y su turno abierto en una sucursal autorizada. Se guardan el titular del turno y quien registró la operación. Este permiso se puede otorgar a un vendedor encargado.
- **Operar caja chica independiente.** Permite operar el fondo asignado. La asignación al fondo y el permiso se validan por separado.
- **Acceso a sucursales.** Las asignaciones de `user_sucursales` delimitan las operaciones de Caja chica sobre cajas ajenas, reposiciones entre cajas y revisión de otras sucursales. Rendir un vale o adjuntar un comprobante exige acceso a su caja de origen, incluso con sobrante cero. El cierre de un turno ajeno y la autorización para dejar un vale pendiente se comprueban por separado.
- **Revisar y autorizar excepciones.** Permite revisar movimientos, aceptar gastos sin comprobante definitivo y autorizar el cierre con vales pendientes, siempre dentro de las sucursales autorizadas. Cerrar el turno ajeno requiere autorización específica y registro del responsable.
- Los permisos de caja chica son independientes de los permisos para cobrar ventas, cerrar turnos y administrar categorías o fondos.

## Operaciones

1. **Registrar gasto.** Capturar monto, categoría, concepto y respaldo. Registrar la salida de efectivo una vez. Si se espera respaldo, marcar **Pendiente de comprobante**. Si definitivamente no habrá respaldo, exigir motivo y aprobación de alguien autorizado. El dinero ya salido se incluye en el arqueo aunque el respaldo siga pendiente.
2. **Entregar vale.** Capturar monto, persona que recibe el efectivo y concepto. Registrar la salida de inmediato. El vale queda **Pendiente de rendir**.
3. **Rendir vale.** Registrar gasto real, respaldo y sobrante recibido. En la primera versión la rendición liquida todo el vale. Gasto más sobrante debe igualar el monto entregado. Admitir gasto total sin sobrante y devolución total sin gasto. Si se gastó más de lo entregado, el excedente queda fuera de esta rendición. Registrar solo la entrada del sobrante, vinculada al vale. Una rendición repetida no puede generar otro ingreso.
4. **Reponer fondo.** Registrar la entrada de efectivo con su procedencia. Si sale de otro cajón del ERP, registrar la salida y la entrada enlazadas, en una sola operación, con permisos sobre los fondos involucrados.
5. **Vincular compra o entrada express.** Permitir una referencia opcional a la compra cuando el gasto o la rendición respalda ese pago. Detectar un pago de caja chica ya registrado para esa compra y validar la relación dentro del mismo negocio. El vínculo no genera una recepción ni un movimiento de stock.
6. **Corregir un registro.** Exigir permiso y motivo. Conservar el original y vincular la corrección. Distinguir un error de registro de una devolución real de efectivo. Si el turno original ya cerró, conservar su arqueo. Un ajuste posterior debe quedar identificado como tal y cualquier devolución real se registra en la caja que la recibe.
7. **Cambiar el responsable del fondo.** Registrar el arqueo de entrega, quién entrega y quién recibe. El nuevo responsable recibe el saldo contado. Los vales pendientes conservan a la persona que recibió el efectivo y se asignan para seguimiento sin registrar otra salida.

## Saldo y arqueo

- Efectivo esperado = apertura + cobros en efectivo + entradas de caja chica − reembolsos en efectivo − salidas de caja chica. Para el fondo independiente se aplican su apertura, reposiciones, egresos y devoluciones. QR, tarjeta y transferencia no financian gastos en efectivo.
- Si el efectivo disponible es cero, bloquear gastos y vales. Si el monto supera el saldo, rechazar la operación. Volver a calcular el saldo al confirmar dentro de una transacción que coordine con el cierre y con las demás salidas. Un doble envío no debe duplicar el movimiento.
- En el turno, mostrar gastos, vales entregados y sobrantes por separado. Un turno con movimientos de caja chica pasa por arqueo aunque no haya ventas o su efecto neto sea cero. El consolidado diario toma los movimientos de cada sesión una sola vez.
- Si un vale sigue pendiente al cerrar, mostrar el monto y un acceso a su detalle. El cierre se bloquea salvo autorización con motivo y responsable de seguimiento. Un gasto que solo espera comprobante puede seguir en revisión después del arqueo.
- Si hay una diferencia entre el efectivo teórico y el contado, registrar y justificar el desfase. Para un gasto que ya ocurrió pero aún no se registró y supera el saldo teórico, elevarlo a revisión para conciliar el origen del dinero. El límite sigue aplicando a nuevas entregas de efectivo.
- Si un vale cruza de día, la salida queda en el turno original. El sobrante entra en el fondo o turno abierto que efectivamente lo recibe. Ejemplo: salen BOB 50 ayer, se gastan BOB 35 y regresan BOB 15 hoy. Ayer conserva su salida de BOB 50 y hoy registra la entrada de BOB 15. El comprobante puede registrarse después del cierre sin alterar el arqueo guardado.

## Datos y pantallas

- Mantener el catálogo `categorias_gastos`. El diseño de `caja_chica_movimientos` debe identificar un único origen o destino de efectivo por movimiento: turno de vendedor o fondo independiente. La rendición registra el gasto real, el respaldo, la persona que rinde, quién aprueba cuando corresponda y el vínculo con el sobrante. Conservar los movimientos originales y usar registros vinculados para corregirlos.
- En **Caja**, mostrar las acciones disponibles en el turno y sus movimientos. El selector de caja ajena aparece para usuarios con ese permiso. Mostrar vendedor, sucursal y turno para identificar el cajón.
- En **Caja chica**, mostrar el fondo independiente de la sucursal, su saldo, reposiciones, gastos, vales pendientes y arqueos.
- El fondo muestra sus últimos cinco movimientos. El historial completo tiene paginación y filtros por fecha, tipo y estado. La salida y el sobrante enlazan al mismo detalle del vale.
- En **Cajas del día**, mostrar el impacto en el efectivo y los pendientes por turno, con acceso al detalle y las acciones de revisión autorizadas.

## Orden de implementación y pruebas

1. Configuración, permisos, alcance por sucursal, catálogo y persistencia de fondos y movimientos.
2. Gastos y vales, validación de saldo y registro desde caja propia o ajena.
3. Rendiciones, devoluciones, reposición y vínculo con compras.
4. Integración con arqueo, cierre, historial y consolidado diario.
5. Probar caja vacía, cobros solo por QR, saldo exacto e insuficiente, permiso directo y por rol, aislamiento entre sucursales y negocios, doble envío, turno sin ventas con movimientos, comprobante pendiente, vale rendido al día siguiente, corrección después del cierre y cambio de responsable con vales abiertos.
