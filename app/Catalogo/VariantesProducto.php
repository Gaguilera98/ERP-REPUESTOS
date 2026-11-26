<?php

namespace App\Catalogo;

use App\Compras\Montos;
use App\Enums\ModoMoneda;
use App\Enums\TipoAtributoCategoria;
use App\Models\CondicionProducto;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class VariantesProducto
{
    /**
     * @return array{
     *     con_variantes: bool,
     *     variante_id: int|null,
     *     sku: string|null,
     *     codigo_barras: string|null,
     *     condicion_producto_id: int|null,
     *     precio_venta: mixed,
     *     precio_mayor: mixed,
     *     ficha: array<string, mixed>,
     *     opciones: list<array{clave: string, valores: list<string>}>,
     *     condiciones_variantes: list<int>,
     *     variantes: list<array<string, mixed>>,
     *     ubicaciones: list<array{id: int, almacen_id: int, ubicacion: string|null}>
     * }
     */
    public function estadoDesde(ProductoMaestro $producto): array
    {
        $producto->loadMissing(['variantes.stocks', 'categoria.atributos']);

        $variantes = $producto->variantes->sortBy('id')->values();
        $vacio = [
            'con_variantes' => false,
            'variante_id' => null,
            'sku' => null,
            'codigo_barras' => null,
            'condicion_producto_id' => null,
            'precio_venta' => null,
            'precio_mayor' => null,
            'ficha' => [],
            'opciones' => [],
            'condiciones_variantes' => [],
            'variantes' => [],
            'ubicaciones' => [],
        ];

        $unica = $variantes->count() <= 1 ? $variantes->first() : null;

        if ($variantes->count() <= 1) {
            return [
                ...$vacio,
                'variante_id' => $unica?->id,
                'sku' => $unica?->sku,
                'codigo_barras' => $unica?->codigo_barras,
                'condicion_producto_id' => $unica?->condicion_producto_id,
                'precio_venta' => $unica?->precio_venta,
                'precio_mayor' => $unica?->precio_mayor,
                'precio_venta_dolar' => $unica?->precio_venta_dolar,
                'precio_mayor_dolar' => $unica?->precio_mayor_dolar,
                'ficha' => $this->fichaParaFormulario($unica?->atributos_json ?? [], $producto),
                'ubicaciones' => $this->ubicaciones($unica),
            ];
        }

        $listas = $producto->categoria?->atributos
            ->where('tipo', TipoAtributoCategoria::Lista)
            ->keyBy('clave') ?? collect();

        $claves = $variantes
            ->flatMap(fn (ProductoVariante $variante): array => array_keys($variante->atributos_json ?? []))
            ->unique();

        $opciones = [];
        $ficha = [];

        foreach ($claves as $clave) {
            $valores = $variantes
                ->map(fn (ProductoVariante $variante) => $variante->atributos_json[$clave] ?? null)
                ->filter(fn (mixed $valor): bool => filled($valor))
                ->unique()
                ->values();

            if ($listas->has($clave) && $valores->count() > 1 && $valores->every(fn (mixed $valor): bool => is_scalar($valor))) {
                $opciones[] = [
                    'clave' => (string) $clave,
                    'valores' => $valores->map(fn (mixed $valor): string => (string) $valor)->all(),
                ];

                continue;
            }

            $valor = $valores->first();
            $ficha[(string) $clave] = $listas->has($clave)
                ? $this->listaParaFormulario($valor)
                : $valor;
        }

        $clavesEje = Arr::pluck($opciones, 'clave');

        return [
            ...$vacio,
            'con_variantes' => true,
            'condiciones_variantes' => $variantes->pluck('condicion_producto_id')->filter()->unique()->count() > 1
                ? $variantes->pluck('condicion_producto_id')->filter()->unique()->values()->all() : [],
            'ficha' => $ficha,
            'opciones' => $opciones,
            'variantes' => $variantes->map(function (ProductoVariante $variante) use ($clavesEje): array {
                $ejes = [];

                foreach ($clavesEje as $clave) {
                    $valor = $variante->atributos_json[$clave] ?? null;

                    if (filled($valor)) {
                        $ejes[$clave] = (string) $valor;
                    }
                }

                return [
                    'id' => $variante->id,
                    'ejes_json' => json_encode($ejes, JSON_THROW_ON_ERROR),
                    'sku' => $variante->sku,
                    'codigo_barras' => $variante->codigo_barras,
                    'condicion_producto_id' => $variante->condicion_producto_id,
                    'imagen' => $variante->imagen,
                    'precio_venta' => $variante->precio_venta,
                    'precio_mayor' => $variante->precio_mayor,
                    'precio_venta_dolar' => $variante->precio_venta_dolar,
                    'precio_mayor_dolar' => $variante->precio_mayor_dolar,
                    'is_active' => $variante->is_active,
                    'ubicaciones' => $this->ubicaciones($variante),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $opciones
     * @param  array<int|string, mixed>  $actuales
     * @param  list<int|string>  $condiciones
     * @return list<array<string, mixed>>
     */
    public function combinar(
        array $opciones,
        array $actuales,
        mixed $skuSimple = null,
        mixed $precioSimple = null,
        mixed $precioMayorSimple = null,
        mixed $codigoBarrasSimple = null,
        mixed $menorUsdSimple = null,
        mixed $mayorUsdSimple = null,
        mixed $condicionSimple = null,
        array $condiciones = [],
        mixed $varianteSimpleId = null,
        ?string $codigoComun = null,
    ): array {
        $ejes = collect($opciones)
            ->filter(fn (mixed $opcion): bool => is_array($opcion) && filled($opcion['clave'] ?? null))
            ->map(function (array $opcion): array {
                $valores = array_values(array_filter(
                    Arr::wrap($opcion['valores'] ?? []),
                    fn (mixed $valor): bool => filled($valor),
                ));

                return [
                    'clave' => (string) $opcion['clave'],
                    'valores' => array_map(fn (mixed $valor): string => (string) $valor, $valores),
                ];
            })
            ->filter(fn (array $opcion): bool => $opcion['valores'] !== [])
            ->values();

        if ($ejes->isEmpty() && $condiciones === []) {
            return [];
        }

        $combinaciones = [[]];

        foreach ($ejes as $eje) {
            $siguiente = [];

            foreach ($combinaciones as $base) {
                foreach ($eje['valores'] as $valor) {
                    $siguiente[] = $base + [$eje['clave'] => $valor];
                }
            }

            $combinaciones = $siguiente;
        }

        $porClave = collect($actuales)
            ->filter(fn (mixed $fila): bool => is_array($fila))
            ->keyBy(fn (array $fila): string => $this->claveEjes($this->ejes($fila)).($condiciones !== [] ? '|condicion:'.($fila['condicion_producto_id'] ?? '') : ''));

        $habia = $porClave->isNotEmpty();
        $condicionesCatalogo = $condiciones !== [] ? CondicionProducto::query()->whereIn('id', $condiciones)->get()->keyBy('id') : collect();
        $filas = [];
        $simpleUtilizada = false;

        foreach ($combinaciones as $ejes) {
            foreach ($condiciones !== [] ? array_values(array_unique($condiciones)) : [null] as $condicionId) {
                $previa = $porClave->get($this->claveEjes($ejes).($condiciones !== [] ? '|condicion:'.$condicionId : ''), []);
                $usarSimple = ! $habia && ! $simpleUtilizada && ($condiciones === [] || (string) $condicionId === (string) $condicionSimple);
                $simpleUtilizada = $simpleUtilizada || $usarSimple;
                $codigo = $condicionesCatalogo->get($condicionId)?->codigo;
                $skuSugerido = filled($codigoComun) && $codigo !== null
                    ? $codigoComun.'-'.str($codigo)->ascii()->upper()->toString().($ejes !== [] ? '-'.str(implode('-', $ejes))->ascii()->upper()->toString() : '') : null;

                $filas[] = [
                    'id' => $previa['id'] ?? ($usarSimple ? $varianteSimpleId : null),
                    'ejes_json' => json_encode($ejes, JSON_THROW_ON_ERROR),
                    'sku' => $previa['sku'] ?? (($usarSimple && filled($skuSimple)) ? $skuSimple : $skuSugerido),
                    'codigo_barras' => $previa['codigo_barras'] ?? ($usarSimple ? $codigoBarrasSimple : null),
                    'condicion_producto_id' => array_key_exists('condicion_producto_id', $previa) ? $previa['condicion_producto_id'] : ($condicionId ?? $condicionSimple),
                    'imagen' => $previa['imagen'] ?? null,
                    'precio_venta' => $previa['precio_venta'] ?? $precioSimple,
                    'precio_mayor' => $previa['precio_mayor'] ?? $precioMayorSimple,
                    'precio_venta_dolar' => $previa === [] ? $menorUsdSimple : ($previa['precio_venta_dolar'] ?? null),
                    'precio_mayor_dolar' => $previa === [] ? $mayorUsdSimple : ($previa['precio_mayor_dolar'] ?? null),
                    'is_active' => $previa['is_active'] ?? true,
                    'ubicaciones' => $previa['ubicaciones'] ?? [],
                ];
            }
        }

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $estado
     * @return list<array{
     *     id: int|string|null,
     *     sku: mixed,
     *     codigo_barras: string|null,
     *     imagen: string|null,
     *     precio_venta: mixed,
     *     precio_mayor: string|null,
     *     atributos_json: array<string, mixed>,
     *     ubicaciones: list<mixed>
     * }>
     */
    public function filas(array $estado): array
    {
        $ficha = $this->limpiarFicha($estado['ficha'] ?? []);
        $conVariantes = filter_var($estado['con_variantes'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $conVariantes) {
            return [[
                'id' => $estado['variante_id'] ?? null,
                'sku' => $estado['sku'] ?? null,
                'codigo_barras' => $this->texto($estado['codigo_barras'] ?? null),
                ...Arr::only($estado, ['condicion_producto_id']),
                'imagen' => null,
                'precio_venta' => $estado['precio_venta'] ?? null,
                'precio_mayor' => $this->texto($estado['precio_mayor'] ?? null),
                ...Arr::only($estado, ['precio_venta_dolar', 'precio_mayor_dolar']),
                'atributos_json' => $ficha,
                'ubicaciones' => array_values(is_array($estado['ubicaciones'] ?? null) ? $estado['ubicaciones'] : []),
            ]];
        }

        $filas = [];

        foreach ($estado['variantes'] ?? [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $item = [
                'id' => $fila['id'] ?? null,
                'sku' => $fila['sku'] ?? null,
                'codigo_barras' => $this->texto($fila['codigo_barras'] ?? null),
                ...Arr::only($fila, ['condicion_producto_id']),
                'imagen' => $this->archivo($fila['imagen'] ?? null),
                'precio_venta' => $fila['precio_venta'] ?? null,
                'precio_mayor' => $this->texto($fila['precio_mayor'] ?? null),
                ...Arr::only($fila, ['precio_venta_dolar', 'precio_mayor_dolar']),
                'atributos_json' => array_merge($ficha, $this->ejes($fila)),
                'ubicaciones' => array_values(is_array($fila['ubicaciones'] ?? null) ? $fila['ubicaciones'] : []),
            ];

            if (array_key_exists('is_active', $fila)) {
                $item['is_active'] = filter_var($fila['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            $filas[] = $item;
        }

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    public function guardar(ProductoMaestro $producto, array $estado): void
    {
        $filas = $this->filas($estado);
        $conVariantes = filter_var($estado['con_variantes'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($conVariantes && count($filas) < 2) {
            throw ValidationException::withMessages(['data.variantes' => 'Elegí al menos dos condiciones o combinaciones. Para una sola, desactivá «Agregar variantes».']);
        }
        $combinaciones = [];
        foreach ($conVariantes ? ($estado['variantes'] ?? []) : [] as $fila) {
            if (! is_array($fila) || blank($fila['condicion_producto_id'] ?? null)) {
                continue;
            }
            $clave = $this->claveEjes($this->ejes($fila)).'|condicion:'.$fila['condicion_producto_id'];
            if (isset($combinaciones[$clave])) {
                throw ValidationException::withMessages(['data.variantes' => 'Hay dos variantes con la misma condición y opciones. Conservá una sola fila por combinación.']);
            }
            $combinaciones[$clave] = true;
        }
        $retiradas = $producto->variantes()->whereNotIn('id', array_filter(Arr::pluck($filas, 'id')))->pluck('id');
        if ($retiradas->isNotEmpty() && (InventarioStock::query()->whereIn('producto_variante_id', $retiradas)
            ->where(fn ($query) => $query->where('cantidad_actual', '<>', 0)->orWhere('cantidad_reservada', '<>', 0))->exists()
            || MovimientoInventario::query()->whereIn('producto_variante_id', $retiradas)->exists())) {
            throw ValidationException::withMessages(['data.variantes' => 'No podés quitar variantes con stock, reservas o movimientos. Conservá la variante y desactivá «Se vende» si ya no la ofrecés.']);
        }
        $conservados = [];

        foreach ($filas as $fila) {
            $payload = Arr::except($fila, ['id', 'ubicaciones']);
            $variante = filled($fila['id'])
                ? $producto->variantes()->whereKey($fila['id'])->first()
                : null;

            if ($variante) {
                $variante->update($payload);
            } else {
                $variante = $producto->variantes()->create($payload);
            }

            $this->guardarUbicaciones($variante, $fila['ubicaciones']);
            $conservados[] = $variante->id;
        }

        $sobrantes = $producto->variantes()->when(
            $conservados !== [],
            fn ($consulta) => $consulta->whereNotIn('id', $conservados),
        );

        $sobrantes->get()->each->delete();

        $imagenes = collect($producto->imagenes ?? [])
            ->merge(collect($filas)->pluck('imagen'))
            ->filter(fn (mixed $imagen): bool => filled($imagen))
            ->map(fn (mixed $imagen): string => (string) $imagen)
            ->unique()
            ->values()
            ->all();

        if ($imagenes !== ($producto->imagenes ?? [])) {
            $producto->update(['imagenes' => $imagenes === [] ? null : $imagenes]);
        }
    }

    /** @param array<string, mixed> $estado
     * @return array<string, mixed>
     */
    public function prepararPrecios(array $estado, PreciosDeReferencia $precios): array
    {
        $configuracion = NegocioConfiguracion::query()->lockForUpdate()->first();
        $habilitado = $configuracion?->modo_moneda === ModoMoneda::ConDolar;
        $cotizacion = $habilitado ? $precios->vigente($configuracion->moneda->value)?->valor : null;

        if (! filter_var($estado['con_variantes'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->prepararPreciosDeFila($estado, 'data', $precios, $habilitado, $cotizacion);
        }

        foreach ($estado['variantes'] ?? [] as $clave => $fila) {
            $estado['variantes'][$clave] = $this->prepararPreciosDeFila($fila, "data.variantes.{$clave}", $precios, $habilitado, $cotizacion);
        }

        return $estado;
    }

    /** @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function prepararPreciosDeFila(array $fila, string $ruta, PreciosDeReferencia $precios, bool $habilitado, ?string $cotizacion): array
    {
        $menorUsd = $precios->referencia($fila['precio_venta_dolar'] ?? null, "{$ruta}.precio_venta_dolar");
        $mayorUsd = $precios->referencia($fila['precio_mayor_dolar'] ?? null, "{$ruta}.precio_mayor_dolar");
        $menor = $this->texto($fila['precio_venta'] ?? null);
        $mayor = $this->texto($fila['precio_mayor'] ?? null);
        if ($menorUsd === null && $mayorUsd === null) {
            if ($menor !== null && $mayor !== null && Montos::comparar($mayor, $menor, 2) > 0) {
                throw ValidationException::withMessages(["{$ruta}.precio_mayor" => 'El precio Mayor supera al Menor.']);
            }

            return $fila;
        }

        if (! $habilitado || $cotizacion === null) {
            $campo = $menorUsd !== null ? 'precio_venta_dolar' : 'precio_mayor_dolar';
            throw ValidationException::withMessages(["{$ruta}.{$campo}" => 'Activá el dólar de referencia y publicá un tipo de cambio comercial.']);
        }

        $convertidos = $precios->convertir(
            $menor,
            $mayor,
            $menorUsd,
            $mayorUsd,
            $cotizacion,
        );
        $problema = $precios->problema($convertidos['precio_venta'], $convertidos['precio_mayor']);
        if ($problema !== null) {
            $campo = $mayorUsd !== null ? 'precio_mayor_dolar' : 'precio_venta_dolar';
            throw ValidationException::withMessages(["{$ruta}.{$campo}" => $problema]);
        }

        return array_replace($fila, $convertidos, ['precio_venta_dolar' => $menorUsd, 'precio_mayor_dolar' => $mayorUsd]);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, string>
     */
    public function ejes(array $fila): array
    {
        $ejes = json_decode((string) ($fila['ejes_json'] ?? '[]'), true);

        if (! is_array($ejes)) {
            return [];
        }

        $limpios = [];

        foreach ($ejes as $clave => $valor) {
            if (filled($valor)) {
                $limpios[(string) $clave] = (string) $valor;
            }
        }

        return $limpios;
    }

    public function etiqueta(array $fila): string
    {
        $valores = array_values($this->ejes($fila));

        if (filled($fila['condicion_producto_id'] ?? null)) {
            $nombre = CondicionProducto::query()->whereKey($fila['condicion_producto_id'])->value('nombre');
            if ($nombre !== null) {
                array_unshift($valores, $nombre);
            }
        }

        return $valores === [] ? 'Variante' : implode(' · ', $valores);
    }

    /**
     * @param  list<mixed>  $ubicaciones
     */
    private function guardarUbicaciones(ProductoVariante $variante, array $ubicaciones): void
    {
        foreach ($ubicaciones as $ubicacion) {
            if (! is_array($ubicacion) || ! filled($ubicacion['id'] ?? null)) {
                continue;
            }

            $stock = $variante->stocks()->whereKey($ubicacion['id'])->getQuery();
            $usuario = auth('tenant')->user();
            abort_unless($usuario instanceof TenantUser, 403);
            app(AccesoSucursales::class)->filtrarAlmacen($stock, $usuario)->update([
                'ubicacion' => $this->texto($ubicacion['ubicacion'] ?? null),
            ]);
        }
    }

    /**
     * @return list<array{id: int, almacen_id: int, ubicacion: string|null}>
     */
    private function ubicaciones(?ProductoVariante $variante): array
    {
        if (! $variante) {
            return [];
        }

        $acceso = app(AccesoSucursales::class);
        $usuario = auth('tenant')->user();
        $stocks = $usuario instanceof TenantUser
            ? $acceso->filtrarAlmacen($variante->stocks()->getQuery(), $usuario)->get() : collect();

        return $stocks
            ->sortBy('id')
            ->map(fn (InventarioStock $stock): array => [
                'id' => $stock->id,
                'almacen_id' => $stock->almacen_id,
                'ubicacion' => $stock->ubicacion,
            ])
            ->values()
            ->all();
    }

    /**
     * La ficha muestra cada lista como varios valores. Un texto viejo ("ZZ") vuelve a ser ["ZZ"].
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function fichaParaFormulario(array $atributos, ProductoMaestro $producto): array
    {
        $listas = $producto->categoria?->atributos
            ->where('tipo', TipoAtributoCategoria::Lista)
            ->keyBy('clave') ?? collect();

        $ficha = [];

        foreach ($atributos as $clave => $valor) {
            if ($listas->has($clave)) {
                $lista = $this->listaParaFormulario($valor);

                if ($lista !== []) {
                    $ficha[(string) $clave] = $lista;
                }

                continue;
            }

            $ficha[(string) $clave] = $valor;
        }

        return $ficha;
    }

    /**
     * @return list<string>
     */
    private function listaParaFormulario(mixed $valor): array
    {
        $items = is_array($valor) ? $valor : [$valor];
        $limpios = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $texto = trim((string) $item);

            if ($texto !== '') {
                $limpios[] = $texto;
            }
        }

        return array_values(array_unique($limpios));
    }

    /**
     * @return array<string, mixed>
     */
    private function limpiarFicha(mixed $ficha): array
    {
        if (! is_array($ficha)) {
            return [];
        }

        return array_filter(
            $ficha,
            fn (mixed $valor): bool => filled(is_string($valor) ? trim($valor) : $valor),
        );
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function archivo(mixed $valor): ?string
    {
        if (is_array($valor)) {
            $valor = Arr::first($valor);
        }

        return $this->texto($valor);
    }

    /**
     * @param  array<string, string>  $ejes
     */
    private function claveEjes(array $ejes): string
    {
        ksort($ejes);

        return collect($ejes)
            ->map(fn (string $valor, string $clave): string => $clave.'='.$valor)
            ->implode('|');
    }
}
