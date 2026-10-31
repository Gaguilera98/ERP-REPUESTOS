<?php

namespace App\Catalogo;

use App\Models\InventarioStock;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\TipoAtributoCategoria;
use Illuminate\Support\Arr;

class VariantesProducto
{
    /**
     * @return array{
     *     con_variantes: bool,
     *     variante_id: int|null,
     *     sku: string|null,
     *     codigo_barras: string|null,
     *     precio_venta: mixed,
     *     precio_mayor: mixed,
     *     ficha: array<string, mixed>,
     *     opciones: list<array{clave: string, valores: list<string>}>,
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
            'precio_venta' => null,
            'precio_mayor' => null,
            'ficha' => [],
            'opciones' => [],
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
                'precio_venta' => $unica?->precio_venta,
                'precio_mayor' => $unica?->precio_mayor,
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
                    'imagen' => $variante->imagen,
                    'precio_venta' => $variante->precio_venta,
                    'precio_mayor' => $variante->precio_mayor,
                    'is_active' => $variante->is_active,
                    'ubicaciones' => $this->ubicaciones($variante),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $opciones
     * @param  array<int|string, mixed>  $actuales
     * @return list<array<string, mixed>>
     */
    public function combinar(
        array $opciones,
        array $actuales,
        mixed $skuSimple = null,
        mixed $precioSimple = null,
        mixed $precioMayorSimple = null,
        mixed $codigoBarrasSimple = null,
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

        if ($ejes->isEmpty()) {
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
            ->keyBy(fn (array $fila): string => $this->claveEjes($this->ejes($fila)));

        $habia = $porClave->isNotEmpty();

        return array_map(function (array $ejes, int $indice) use ($porClave, $habia, $skuSimple, $precioSimple, $precioMayorSimple, $codigoBarrasSimple): array {
            $previa = $porClave->get($this->claveEjes($ejes), []);

            return [
                'id' => $previa['id'] ?? null,
                'ejes_json' => json_encode($ejes, JSON_THROW_ON_ERROR),
                'sku' => $previa['sku'] ?? ((! $habia && $indice === 0) ? $skuSimple : null),
                'codigo_barras' => $previa['codigo_barras'] ?? ((! $habia && $indice === 0) ? $codigoBarrasSimple : null),
                'imagen' => $previa['imagen'] ?? null,
                'precio_venta' => $previa['precio_venta'] ?? $precioSimple,
                'precio_mayor' => $previa['precio_mayor'] ?? $precioMayorSimple,
                'is_active' => $previa['is_active'] ?? true,
                'ubicaciones' => $previa['ubicaciones'] ?? [],
            ];
        }, $combinaciones, array_keys($combinaciones));
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
                'imagen' => null,
                'precio_venta' => $estado['precio_venta'] ?? null,
                'precio_mayor' => $this->texto($estado['precio_mayor'] ?? null),
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
                'imagen' => $this->archivo($fila['imagen'] ?? null),
                'precio_venta' => $fila['precio_venta'] ?? null,
                'precio_mayor' => $this->texto($fila['precio_mayor'] ?? null),
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

            $variante->stocks()->whereKey($ubicacion['id'])->update([
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

        return $variante->stocks
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
