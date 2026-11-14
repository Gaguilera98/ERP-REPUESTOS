# Despliegue y datos base de los negocios

## Qué corre en cada despliegue hoy

En Laravel Cloud, el ambiente tiene configurado el siguiente comando de despliegue. La primera parte aplica las migraciones pendientes en la base central. La segunda aplica las migraciones pendientes en el pool de negocios.

```bash
php artisan migrate --force && php artisan migrate --database=pool_shared_1 --path=database/migrations/tenant --force
```

La primera parte ya incluye `php artisan migrate --force`. Con que aparezca una sola vez alcanza. El operador `&&` hace que el segundo paso arranque solo si el primero terminó bien. Laravel registra las migraciones aplicadas por conexión, así que el comando puede ejecutarse en cada despliegue. [Despliegues en Laravel Cloud](https://laravel.com/cloud/docs/deployments)

**Hoy hay un solo pool, `pool_shared_1`.** La segunda parte migra sus tablas compartidas una vez y todos los negocios de ese pool usan el esquema actualizado. Cada negocio mantiene sus propios registros por `tenant_id`. La configuración de pools está en `config/tenant-pools.php` y cada negocio guarda el nombre de su conexión en `tenants.db_pool`.

## Qué recibe un negocio nuevo

Al crear un tenant, el evento `TenantCreated` ejecuta `SeedNegocioRoles` en la conexión asignada al negocio. Ese proceso prepara roles, permisos, configuración inicial, proveedor ocasional, tipos de documento y el catálogo de unidades base de la versión desplegada. El cambio de unidades de ficha agrega esa carga al mismo proceso que ya prepara las unidades de stock.

- **Stock, compras y ventas.** La carga de unidades de cantidad incluye `pza`, `g`, `kg`, `ml`, `L`, `m` y `cm`, con sus factores y decimales.
- **Ficha técnica.** La carga de atributos incluye `mm`, `in`, `V`, `A` y `bar`. El selector de la ficha también admite unidades físicas que ya existen para stock, como `cm` y `m`, usando el mismo registro.

La creación del negocio carga los datos base automáticamente. `DatabaseSeeder` y `CatalogoSeeder` cumplen otra función. El primero puede crear un usuario de prueba en la base central. El segundo escribe datos de ejemplo de Rodamientos y actualiza sus atributos. Por eso el deploy de producción debe llevar comandos de migración, sin `--seed`. Tampoco corresponde volver a ejecutar `SeedNegocioRoles` como mecanismo de actualización general, ya que sincroniza los permisos de los roles predefinidos.

## Cómo agregar unidades base a todos los negocios

1. Agregar la unidad al catálogo de alta que corresponda, de cantidad o de atributo. Así la incorporan los negocios creados a partir de esa versión.
2. Incluir una migración de datos que distribuya esa incorporación a los negocios existentes. Definir en esa migración las unidades de esa versión. Cuando agreguemos otra unidad después, crear otra migración, porque Laravel ya habrá marcado la anterior como aplicada.
3. Buscar coincidencias por **símbolo y nombre dentro del mismo tenant**. Si ya existe una unidad compatible, conservarla. Si el símbolo o el nombre pertenece a una unidad con otra definición, conservarla y reportar el conflicto para revisión. Cambiar el uso, símbolo o factor de un registro ya referenciado requiere una decisión explícita, sobre todo si interviene en movimientos de stock.
4. Probar el alta de un tenant, la actualización de uno existente, una segunda ejecución y los casos de colisión de nombre o símbolo. Verificar también que los demás negocios del pool conserven sus datos.

La migración `2026_11_14_022917_asegurar_unidades_de_atributo_en_negocios.php` está en `database/migrations/tenant`. Usa la conexión seleccionada por el comando de migración, completa las unidades faltantes y registra un aviso cuando un nombre o símbolo pertenece a una unidad con otra definición.

## Qué cambia cuando haya más pools

1. Registrar cada conexión en `config/database.php` y cada pool en `config/tenant-pools.php`.
2. Migrar la base central y después **cada pool configurado**, una vez por conexión. Hoy el deploy enumera un solo pool. Cuando haya varios, conviene reemplazar esa lista manual por un comando del proyecto que recorra los pools registrados y detenga el despliegue si alguno falla. Ese comando todavía está pendiente.
3. Ejecutar las migraciones de datos de unidades desde `database/migrations/tenant`, usando la conexión activa de cada pool. Así el esquema del pool y sus datos base se aplican en orden, incluso si el pool es nuevo. Dentro de esa conexión, distribuir las unidades a cada tenant del pool.

Con ese flujo, el alta prepara cada negocio nuevo y los despliegues aplican las incorporaciones pendientes a los negocios existentes de cada pool.
