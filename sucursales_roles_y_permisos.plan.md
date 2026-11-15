# Sucursales, roles y permisos del negocio

**Estado** — Implementación local. El alcance por sucursal aplica desde el alta del negocio.

## Reglas de acceso

- El rol y los permisos directos definen qué puede hacer una persona. Las sucursales asignadas definen dónde puede hacerlo. El servidor comprueba ambas condiciones dentro del tenant actual.
- **Administrador del negocio.** Administra usuarios, roles, permisos, sucursales, configuración y catálogos. Tiene alcance sobre todas las sucursales del negocio, incluidas las nuevas. El admin central de la plataforma conserva su ámbito propio.
- **Supervisor.** Supervisa ventas, cajas y stock de sus sucursales asignadas según sus permisos.
- **Vendedor.** Opera en una o varias sucursales asignadas. Su clave interna es `vendedor`.
- Una persona tiene una sola cuenta por negocio. Reutilizar `user_sucursales` para asignarle una o varias sucursales. Los usuarios, roles, permisos, productos, categorías, marcas y unidades de medida se administran a nivel del negocio.
- El producto existe una sola vez en el catálogo. El stock se registra por SKU y almacén. El usuario con permiso de stock ve los almacenes vinculados a sus sucursales autorizadas. Un almacén compartido se cuenta una sola vez en los totales.

## Flujo en pantalla

### Operar desde la sucursal activa

1. Al iniciar sesión, seleccionar automáticamente la sucursal si hay una sola habilitada. Si hay varias, mostrar **¿En qué sucursal vas a trabajar?**. Si un usuario operativo no tiene ninguna asignada, indicar que el administrador debe asignarle una y bloquear las operaciones que requieren sucursal.
2. Mostrar **Sucursal activa** en el encabezado. Con una sola, mostrar su nombre. Con varias, permitir cambiar entre las autorizadas. Conservar la elección durante la sesión y volver a validarla si cambian las asignaciones o se desactiva la sucursal.
3. En **Mostrador**, mostrar siempre el nombre de la sucursal y el almacén que abastece la venta. Seleccionar el almacén principal por defecto y ofrecer los demás almacenes vinculados cuando corresponda. Si falta un almacén habilitado, indicar qué debe configurarse.
4. Al cobrar, comprobar que el carrito, la sucursal activa, el almacén y el turno abierto del vendedor correspondan a la misma operación. Si falta abrir caja en ese local, mostrar el aviso y ofrecer ir a Caja.
5. Si se intenta cambiar de sucursal con un carrito en curso, pedir terminar la venta o descartar el carrito. Una pestaña con un carrito anterior debe pedir revisión si la sucursal cambió en otra pestaña. Cada turno conserva su sucursal y su historial al cambiar de local.

### Consultar sin cambiar dónde se trabaja

- **Productos** muestra el catálogo común a quien tenga permiso de verlo. **Stock** muestra únicamente existencias y totales de los almacenes autorizados, con filtro local cuando hay más de una sucursal consultable.
- **Caja** opera el turno propio en la sucursal activa. **Cajas del día** permite consultar turnos de otros vendedores si el usuario tiene ese permiso, mediante un filtro de las sucursales autorizadas. El cierre general se realiza sobre la sucursal específica mostrada y requiere su permiso.
- **Ventas** y **Stock** pueden ofrecer **Todas las asignadas** cuando la vista admita consolidación. Al abrir una consulta, sugerir la sucursal activa. Cambiar el filtro solo modifica los resultados de esa pantalla.
- En **Caja chica**, la sucursal asignada delimita el acceso. Las operaciones sobre fondos y turnos siguen sujetas a sus permisos y a las reglas de responsabilidad del fondo.
- Para compras y traslados, mostrar los almacenes autorizados en el formulario. Identificar con claridad el destino de la recepción y, en traslados, el origen y el destino. Validar ambos extremos en el servidor.

### Administrar usuarios y permisos extras

- En alta y edición de usuarios, mostrar **Rol** y **Sucursales asignadas**. Para el administrador del negocio, indicar que tiene alcance a todas. El campo actual «Sucursales de caja chica» pasa a representar la asignación general.
- En el listado y la edición, agregar **Permisos extras** como acción modal de Filament. Agrupar los permisos de Shield y Spatie con etiquetas legibles y buscador. Mostrar los permisos heredados marcados y bloqueados. Guardar únicamente los cambios a permisos directos.
- Un permiso directo que ya exista se conserva al cambiar de rol, incluso si ahora también se hereda. Reservar la administración de roles, asignaciones y permisos a quien esté autorizado para otorgarlos. Proteger al último administrador activo del negocio y registrar quién cambió el acceso.

## Orden de implementación

1. **Roles y asignaciones.** Crear el administrador del negocio y definir los permisos base de Supervisor y Vendedor. Reutilizar las asignaciones existentes y los permisos directos de Caja chica. Preparar el alta para que el titular de un negocio nuevo nazca como administrador.
2. **Autorización.** Ampliar `AccesoSucursales` como punto común para resolver sucursales y almacenes autorizados. Aplicar el alcance en consultas, totales, accesos por ID, detalles en modales, exportaciones y escrituras. Usar el permiso para autorizar cada acción y comprobar que el registro pertenece al tenant y al alcance del usuario.
3. **Sucursal activa.** Agregar la selección al entrar y el control del encabezado. Integrar Mostrador y Caja con las validaciones de carrito, almacén y turno. Resolver el comportamiento ante cambios en otra pestaña o asignaciones revocadas durante la sesión.
4. **Filtros de consulta y permisos extras.** Incorporar los filtros por sucursal en los módulos correspondientes y mover la edición de permisos directos al modal de Usuarios.

## Pruebas de aceptación

- Vendedor con una sucursal entra directo y su venta usa la caja y un almacén de ese local.
- Vendedor con dos sucursales puede elegir cualquiera. Una consulta de stock en el otro local deja intacta la sucursal de venta. Un cambio durante el cobro o desde otra pestaña impide confirmar con datos de dos locales distintos.
- Supervisor con dos sucursales y permiso de Cajas del día puede consultar ambas. Una tercera queda excluida del selector, los totales y el acceso directo por ID. El permiso para ver cajas ajenas no habilita cerrarlas.
- El administrador del negocio accede también a una sucursal creada después. Un usuario sin sucursales asignadas recibe el aviso y no puede operar allí mediante acceso directo.
- Un almacén compartido aparece con un solo saldo en el consolidado. Un traslado rechaza un origen o destino fuera del alcance.
- El modal conserva los permisos heredados y permite editar los directos dentro de la autoridad de quien los administra. Un usuario no puede ampliar su propio acceso mediante una petición manipulada.
- Los datos de otro tenant permanecen fuera de los selectores, las consultas y las acciones.

## Primera puesta en producción

1. Eliminar el negocio de prueba desde el panel central antes de desplegar esta versión. Conservar la base central, el administrador de plataforma y el pool.
2. Desplegar con el flujo habitual y crear el negocio. El titular recibe el rol Administrador del negocio automáticamente.
3. Crear la primera sucursal con su almacén principal. Asignar las sucursales de los supervisores y vendedores al crear sus cuentas.
