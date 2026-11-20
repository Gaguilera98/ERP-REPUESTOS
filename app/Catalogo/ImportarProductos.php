<?php

namespace App\Catalogo;

use App\Compras\Montos;
use App\Enums\TipoMovimientoInventario;
use App\Enums\UsoUnidad;
use App\Inventario\RegistrarMovimiento;
use App\Jobs\ProcesarImportacionProductos;
use App\Models\Categoria;
use App\Models\ImportacionProducto;
use App\Models\InventarioInicialDetalle;
use App\Models\InventarioStock;
use App\Models\Marca;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class ImportarProductos
{
    public function __construct(
        private RegistrarMovimiento $movimientos,
        private AccesoSucursales $acceso,
        private NormalizarAtributosVariante $atributos,
        private PreciosDeReferencia $precios,
    ) {}

    public function autorizar(TenantUser $usuario, ?int $almacenId = null): void
    {
        abort_unless($usuario->is_active && (string) $usuario->tenant_id === (string) tenant('id')
            && $usuario->can('Importar:Productos') && $usuario->can('ViewAny:ProductoMaestro'), 403);
        if ($almacenId !== null) {
            abort_unless($usuario->can('Cargar:InventarioInicial') && $usuario->can('ViewAny:InventarioStock'), 403);
            $this->acceso->validarAlmacen($usuario, $almacenId);
            $this->movimientos->exigirLibre($almacenId);
        }
    }

    /**
     * @param  list<array<string, string|int>>  $filas
     * @return array{filas: list<array<string, mixed>>, errores: array<int, list<string>>, nuevos: int, actualizados: int, conservados: int, lotes: int, valor: string}
     */
    public function revisar(array $filas, ?int $almacenId, bool $actualizar, TenantUser $usuario): array
    {
        $this->autorizar($usuario, $almacenId);
        if ($filas === [] || count($filas) > ArchivoProductos::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => 'Subí entre 1 y '.ArchivoProductos::MAX_FILAS.' filas.']);
        }
        $categorias = Categoria::query()->where('is_active', true)->get()->groupBy(fn (Categoria $modelo): string => mb_strtolower($modelo->nombre));
        $marcas = Marca::query()->get()->groupBy(fn (Marca $modelo): string => mb_strtolower($modelo->nombre));
        $unidades = UnidadMedida::query()->where('uso', UsoUnidad::Cantidad)->get()->keyBy('simbolo');
        $proveedores = Proveedor::query()->get()->groupBy(fn (Proveedor $modelo): string => mb_strtolower($modelo->nombre_empresa));
        $existentes = ProductoVariante::query()->with(['productoMaestro.variantes', 'productoMaestro.unidadBase'])
            ->whereIn('sku', array_column($filas, 'sku'))->get()->keyBy('sku');
        $conMovimiento = $almacenId === null ? [] : MovimientoInventario::query()->where('almacen_id', $almacenId)
            ->whereIn('producto_variante_id', $existentes->pluck('id'))->pluck('producto_variante_id')->all();
        $conSaldo = $almacenId === null ? [] : InventarioStock::query()->where('almacen_id', $almacenId)
            ->whereIn('producto_variante_id', $existentes->pluck('id'))
            ->where(fn ($query) => $query->where('cantidad_actual', '<>', 0)->orWhere('cantidad_reservada', '<>', 0))
            ->pluck('producto_variante_id')->all();
        $revision = ['filas' => [], 'errores' => [], 'nuevos' => 0, 'actualizados' => 0, 'conservados' => 0, 'lotes' => 0, 'valor' => '0.00'];
        $vistas = [];
        $barras = [];
        $cantidades = [];
        foreach ($filas as $fila) {
            $numero = (int) $fila['fila'];
            try {
                Validator::make($fila, ['sku' => ['required', 'string', 'max:255']], ['sku.required' => 'Ingresá el SKU.'])->validate();
                $sku = (string) $fila['sku'];
                $variante = $existentes->get($sku);
                $accion = $variante ? ($actualizar ? 'actualizar' : 'conservar') : 'crear';
                $producto = $variante?->productoMaestro;
                $datos = [];
                $precios = [];
                if ($accion !== 'conservar') {
                    Gate::forUser($usuario)->authorize($variante ? 'update' : 'create', $producto ?? ProductoMaestro::class);
                    Validator::make($fila, [
                        'nombre' => ['required', 'string', 'max:255'], 'categoria' => ['required', 'string', 'max:255'],
                        'unidad_stock' => ['required', 'string', 'max:255'], 'marca' => ['nullable', 'string', 'max:255'],
                        'codigo_barras' => ['nullable', 'string', 'max:255'], 'atributos' => ['nullable', 'json'],
                    ], ['required' => 'Completá :attribute.', 'json' => 'La ficha de atributos debe ser un objeto JSON.'])->validate();
                    $categoria = $categorias->get(mb_strtolower((string) $fila['categoria']));
                    $marca = filled($fila['marca']) ? $marcas->get(mb_strtolower((string) $fila['marca'])) : null;
                    $unidad = $unidades->get($fila['unidad_stock']);
                    if ($categoria?->count() !== 1) {
                        throw ValidationException::withMessages(['categoria' => 'La categoría «'.$fila['categoria'].'» no coincide con una categoría activa y única del negocio. Elegí la categoría correcta.']);
                    }
                    if (! $unidad) {
                        throw ValidationException::withMessages(['unidad_stock' => 'El símbolo de unidad «'.$fila['unidad_stock'].'» no corresponde a una unidad de stock del negocio.']);
                    }
                    if (filled($fila['marca']) && $marca?->count() !== 1) {
                        throw ValidationException::withMessages(['marca' => 'La marca «'.$fila['marca'].'» no coincide con una marca única del negocio. Elegí la marca correcta.']);
                    }
                    $datos = ['nombre' => $fila['nombre'], 'categoria_id' => $categoria->first()->id, 'unidad_base_id' => $unidad->id,
                        'marca_id' => $marca?->first()?->id ?? $producto?->marca_id];
                    if ($producto && ($producto->categoria_id !== $datos['categoria_id'] || $producto->unidad_base_id !== $unidad->id)) {
                        throw ValidationException::withMessages(['catalogo' => 'La categoría y unidad de un SKU existente se modifican desde su ficha.']);
                    }
                    if ($producto && $producto->variantes->count() > 1 && ($producto->nombre !== $datos['nombre'] || $producto->marca_id !== $datos['marca_id'])) {
                        throw ValidationException::withMessages(['catalogo' => 'Este SKU tiene variantes. Conservá su nombre y marca compartidos.']);
                    }
                    if ($variante && ($variante->precio_venta_dolar !== null || $variante->precio_mayor_dolar !== null)) {
                        throw ValidationException::withMessages(['precios' => 'Este SKU usa referencia en USD. Actualizá sus precios desde la ficha o importá conservando existentes.']);
                    }
                    $menor = $this->decimal($fila['precio_menor'], 'precio_menor', 8, 2);
                    $mayor = filled($fila['precio_mayor']) ? $this->decimal($fila['precio_mayor'], 'precio_mayor', 8, 2) : $variante?->precio_mayor;
                    if ($problema = $this->precios->problema($menor, $mayor)) {
                        throw ValidationException::withMessages(['precios' => $problema]);
                    }
                    $ficha = filled($fila['atributos']) ? json_decode((string) $fila['atributos'], true, flags: JSON_THROW_ON_ERROR) : ($variante?->atributos_json ?? []);
                    if (! is_array($ficha) || (filled($fila['atributos']) && ! str_starts_with(trim((string) $fila['atributos']), '{'))) {
                        throw ValidationException::withMessages(['atributos' => 'La ficha debe ser un objeto JSON con las claves de la categoría.']);
                    }
                    $ficha = $this->atributos->paraCategoria($datos['categoria_id'], $ficha);
                    $codigo = filled($fila['codigo_barras']) ? (string) $fila['codigo_barras'] : $variante?->codigo_barras;
                    if ($codigo !== null) {
                        $otro = ProductoVariante::query()->where('codigo_barras', $codigo)->when($variante, fn ($query) => $query->whereKeyNot($variante->id))->exists();
                        if ($otro || (isset($barras[$codigo]) && $barras[$codigo] !== $sku)) {
                            throw ValidationException::withMessages(['codigo_barras' => 'El código de barras pertenece a otro SKU.']);
                        }
                        $barras[$codigo] = $sku;
                    }
                    $precios = ['precio_venta' => $menor, 'precio_mayor' => $mayor, 'codigo_barras' => $codigo, 'atributos_json' => $ficha];
                } else {
                    $unidad = $producto->unidadBase;
                }
                $cantidad = null;
                $costo = null;
                $proveedorId = null;
                if (filled($fila['cantidad_inicial'])) {
                    if ($almacenId === null) {
                        throw ValidationException::withMessages(['cantidad' => 'Activá la carga de inventario inicial y elegí el almacén.']);
                    }
                    $cantidad = $this->decimal($fila['cantidad_inicial'], 'cantidad_inicial', 10, 4);
                    $costo = $this->decimal($fila['costo_unitario'], 'costo_unitario', 10, 4);
                    if (Montos::comparar($cantidad, '0') <= 0 || ! $unidad?->admiteCantidad($cantidad)) {
                        throw ValidationException::withMessages(['cantidad' => 'La cantidad debe ser positiva y respetar la unidad de stock.']);
                    }
                    if ($variante && in_array($variante->id, [...$conMovimiento, ...$conSaldo], true)) {
                        throw ValidationException::withMessages(['cantidad' => 'Este SKU ya tiene movimientos o saldo en el almacén. Usá compras o ajuste de inventario.']);
                    }
                    if (filled($fila['proveedor'])) {
                        $proveedor = $proveedores->get(mb_strtolower((string) $fila['proveedor']));
                        if ($proveedor?->count() !== 1) {
                            throw ValidationException::withMessages(['proveedor' => 'Elegí un nombre de proveedor existente y único del negocio.']);
                        }
                        $proveedorId = $proveedor->first()->id;
                    }
                } elseif (filled($fila['costo_unitario']) || filled($fila['proveedor'])) {
                    throw ValidationException::withMessages(['cantidad' => 'Ingresá la cantidad inicial del lote o dejá vacíos costo y proveedor.']);
                }
                $identidad = json_encode([$datos, $precios], JSON_THROW_ON_ERROR);
                if (isset($vistas[$sku])) {
                    if ($cantidad === null || $vistas[$sku]['cantidad'] === null || $vistas[$sku]['identidad'] !== $identidad) {
                        throw ValidationException::withMessages(['sku' => 'SKU repetido. Para varios lotes, conservá iguales los datos del catálogo e indicá cantidad y costo en cada fila.']);
                    }
                } else {
                    $revision[match ($accion) {
                        'crear' => 'nuevos', 'actualizar' => 'actualizados', default => 'conservados'
                    }]++;
                    $vistas[$sku] = ['identidad' => $identidad, 'cantidad' => $cantidad];
                }
                if ($cantidad !== null) {
                    $cantidades[$sku] = Montos::sumar($cantidades[$sku] ?? '0', $cantidad, 4);
                    if (Montos::comparar($cantidades[$sku], '9999999999.9999') > 0) {
                        throw ValidationException::withMessages(['cantidad' => 'La suma de los lotes del SKU supera la cantidad máxima admitida.']);
                    }
                    $revision['lotes']++;
                    $revision['valor'] = Montos::sumar($revision['valor'], Montos::multiplicar($cantidad, $costo, 4), 4);
                }
                $revision['filas'][] = [
                    'fila' => $numero, 'sku' => $sku, 'nombre' => $datos['nombre'] ?? $producto->nombre, 'accion' => $accion,
                    'variante_id' => $variante?->id, 'producto' => $datos, 'variante' => $precios,
                    'cantidad' => $cantidad, 'costo' => $costo, 'proveedor_id' => $proveedorId, 'proveedor' => $fila['proveedor'],
                    'precio_menor' => $precios['precio_venta'] ?? $variante?->precio_venta,
                    'precio_mayor' => $precios['precio_mayor'] ?? $variante?->precio_mayor,
                    'unidad' => $unidad->simbolo,
                ];
            } catch (ValidationException $exception) {
                $revision['errores'][$numero] = Arr::flatten($exception->errors());
            } catch (JsonException) {
                $revision['errores'][$numero] = ['La ficha técnica contiene JSON inválido.'];
            }
        }

        return $revision;
    }

    private function decimal(mixed $valor, string $campo, int $enteros, int $decimales): string
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if (! preg_match('/^\d{1,'.$enteros.'}(\.\d{1,'.$decimales.'})?$/', $texto)) {
            throw ValidationException::withMessages([$campo => 'Revisá '.$campo.'. Ingresá un número positivo o cero con hasta '.$decimales.' decimales, sin separador de miles.']);
        }

        return Montos::redondear($texto, $decimales);
    }

    /** @param list<array<string, string|int>> $filas */
    public function preparar(array $filas, string $archivo, ?int $almacenId, ?string $fecha, bool $actualizar, TenantUser $usuario): ImportacionProducto
    {
        $revision = $this->revisar($filas, $almacenId, $actualizar, $usuario);
        Validator::make(['fecha' => $fecha], ['fecha' => [$almacenId ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today']], [
            'fecha.required' => 'Elegí la fecha del inventario inicial.', 'fecha.before_or_equal' => 'La fecha del inventario no puede ser futura.',
        ])->validate();
        $destino = $almacenId === null ? ($actualizar ? 'catalogo-actualizar' : 'catalogo-conservar') : 'almacen-'.$almacenId;
        $huella = hash('sha256', json_encode($filas, JSON_THROW_ON_ERROR));
        $datos = [
            'user_id' => $usuario->id, 'almacen_id' => $almacenId, 'destino' => $destino, 'huella' => $huella,
            'archivo' => mb_substr(basename($archivo), 0, 255), 'moneda' => NegocioConfiguracion::codigoMoneda(), 'estado' => 'revision', 'actualizar_existentes' => $actualizar,
            'fecha_inventario' => $fecha, 'filas' => $filas, 'revision' => $revision,
        ];

        return DB::transaction(function () use ($destino, $huella, $datos, $usuario): ImportacionProducto {
            $registro = ImportacionProducto::query()->firstOrCreate(['destino' => $destino, 'huella' => $huella], $datos);
            $importacion = ImportacionProducto::query()->lockForUpdate()->findOrFail($registro->id);
            abort_unless($importacion->user_id === $usuario->id, 403);
            if (! in_array($importacion->estado, ['completada', 'en_cola'], true)) {
                $importacion->fill([...$datos, 'resultado' => null])->save();
            }

            return $importacion;
        });
    }

    public function consultar(int $id, TenantUser $usuario): ImportacionProducto
    {
        abort_unless($usuario->is_active && (string) $usuario->tenant_id === (string) tenant('id')
            && $usuario->can('Importar:Productos') && $usuario->can('ViewAny:ProductoMaestro'), 403);
        $importacion = ImportacionProducto::query()->where('user_id', $usuario->id)->with(['almacen', 'usuario'])->findOrFail($id);
        if ($importacion->almacen_id !== null) {
            abort_unless($usuario->can('Cargar:InventarioInicial') && $usuario->can('ViewAny:InventarioStock')
                && $this->acceso->autorizaAlmacen($usuario, $importacion->almacen_id), 403);
        }

        return $importacion;
    }

    /**
     * @param  list<int>  $numeros
     * @param  array<string, string|null>  $cambios
     */
    public function corregirFilas(int $id, array $numeros, array $cambios, TenantUser $usuario): ImportacionProducto
    {
        $this->consultar($id, $usuario);
        $columnas = ArchivoProductos::COLUMNAS;
        $reglas = [
            'filas' => ['required', 'array', 'min:1', 'max:'.ArchivoProductos::MAX_FILAS],
            'filas.*' => ['required', 'integer', 'min:2'],
            'cambios' => ['required', 'array:'.implode(',', $columnas), 'min:1'],
        ];
        foreach ($columnas as $columna) {
            $reglas['cambios.'.$columna] = ['nullable', 'string', 'max:'.($columna === 'atributos' ? 10000 : 255)];
        }
        Validator::make(['filas' => $numeros, 'cambios' => $cambios], $reglas)->validate();

        return DB::transaction(function () use ($id, $numeros, $cambios, $usuario): ImportacionProducto {
            $importacion = ImportacionProducto::query()->where('user_id', $usuario->id)->lockForUpdate()->findOrFail($id);
            $this->autorizar($usuario, $importacion->almacen_id);
            if (! in_array($importacion->estado, ['revision', 'fallida'], true)) {
                throw ValidationException::withMessages(['importacion' => 'Esta carga ya está confirmada o en proceso. Volvé a consultar su resultado.']);
            }
            $filas = collect($importacion->filas)->keyBy('fila');
            foreach ($numeros as $numero) {
                if (! $filas->has($numero)) {
                    throw ValidationException::withMessages(['filas' => 'La selección contiene una fila que no pertenece a esta carga.']);
                }
                $filas->put($numero, array_replace($filas->get($numero), array_map(fn (?string $valor): string => trim($valor ?? ''), $cambios)));
            }
            $entrada = $filas->values()->all();
            $revision = $this->revisar($entrada, $importacion->almacen_id, $importacion->actualizar_existentes, $usuario);
            $importacion->update(['filas' => $entrada, 'revision' => $revision, 'estado' => 'revision', 'resultado' => null]);

            return $importacion;
        });
    }

    public function confirmar(int $id, TenantUser $usuario, ?string $versionRevision = null): void
    {
        DB::transaction(function () use ($id, $usuario, $versionRevision): void {
            $importacion = ImportacionProducto::query()->lockForUpdate()->findOrFail($id);
            abort_unless($importacion->user_id === $usuario->id, 403);
            $this->autorizar($usuario, $importacion->almacen_id);
            if (in_array($importacion->estado, ['completada', 'en_cola'], true)) {
                return;
            }
            if ($versionRevision !== null && ! hash_equals($importacion->huellaRevision(), $versionRevision)) {
                throw ValidationException::withMessages(['archivo' => 'La revisión cambió. Revisá los datos actualizados antes de confirmar.']);
            }
            if ($importacion->revision['errores'] !== []) {
                throw ValidationException::withMessages(['archivo' => 'Corregí las filas con errores y subí nuevamente el Excel.']);
            }
            $importacion->update(['estado' => 'en_cola', 'resultado' => null]);
            $tenantId = (string) tenant('id');
            DB::afterCommit(function () use ($tenantId, $id): void {
                try {
                    Bus::dispatch(new ProcesarImportacionProductos($tenantId, $id));
                } catch (Throwable $exception) {
                    report($exception);
                    ImportacionProducto::query()->whereKey($id)->where('estado', 'en_cola')->first()?->update([
                        'estado' => 'fallida', 'resultado' => ['aviso' => 'No se pudo iniciar la importación. Podés reintentar la confirmación.'],
                    ]);

                    throw ValidationException::withMessages(['archivo' => 'No se pudo iniciar la importación. Revisá la conexión de la cola y reintentá.']);
                }
            });
        });
    }

    public function ejecutar(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $importacion = ImportacionProducto::query()->lockForUpdate()->findOrFail($id);
            if ($importacion->estado !== 'en_cola') {
                return;
            }
            $usuario = TenantUser::query()->findOrFail($importacion->user_id);
            $this->autorizar($usuario, $importacion->almacen_id);
            $skus = array_unique(array_column($importacion->filas, 'sku'));
            if ($importacion->moneda !== NegocioConfiguracion::codigoMoneda()) {
                throw ValidationException::withMessages(['moneda' => 'Cambió la moneda del negocio. Volvé a cargar el archivo revisando los importes.']);
            }
            ProductoVariante::query()->whereIn('sku', $skus)->orderBy('id')->lockForUpdate()->get();
            $revision = $this->revisar($importacion->filas, $importacion->almacen_id, $importacion->actualizar_existentes, $usuario);
            if ($revision['errores'] !== []) {
                $importacion->update(['estado' => 'revision', 'revision' => $revision]);

                return;
            }
            if ($revision !== $importacion->revision) {
                $importacion->update(['estado' => 'revision', 'revision' => $revision, 'resultado' => ['aviso' => 'Los datos del catálogo cambiaron. Revisá el nuevo resumen y confirmá nuevamente.']]);

                return;
            }
            $variantes = [];
            foreach ($revision['filas'] as $fila) {
                $sku = $fila['sku'];
                if (! isset($variantes[$sku])) {
                    $variante = $fila['variante_id'] ? ProductoVariante::query()->findOrFail($fila['variante_id']) : null;
                    if ($fila['accion'] === 'crear') {
                        $producto = ProductoMaestro::query()->create($fila['producto']);
                        $variante = $producto->variantes()->create(['sku' => $sku, ...$fila['variante']]);
                    } elseif ($fila['accion'] === 'actualizar') {
                        $variante->productoMaestro->update($fila['producto']);
                        $variante->update($fila['variante']);
                    }
                    $variantes[$sku] = $variante;
                }
                if ($fila['cantidad'] !== null) {
                    $lote = InventarioInicialDetalle::query()->create([
                        'importacion_id' => $id, 'producto_variante_id' => $variantes[$sku]->id,
                        'proveedor_id' => $fila['proveedor_id'], 'fila' => $fila['fila'],
                        'cantidad' => $fila['cantidad'], 'costo_unitario' => $fila['costo'],
                    ]);
                    $this->movimientos->mover([
                        'variante' => $variantes[$sku], 'almacen_id' => $importacion->almacen_id, 'user_id' => $usuario->id,
                        'tipo' => TipoMovimientoInventario::InventarioInicial, 'cantidad' => $fila['cantidad'],
                        'sentido' => 'entrada', 'costo_unitario' => $fila['costo'], 'recalcular_promedio' => true,
                        'referencia_tipo' => 'inventario_inicial', 'referencia_id' => $lote->id,
                    ]);
                }
            }
            $importacion->update(['estado' => 'completada', 'completada_at' => now(), 'resultado' => Arr::except($revision, ['filas', 'errores'])]);
        }, attempts: 3);
    }
}
