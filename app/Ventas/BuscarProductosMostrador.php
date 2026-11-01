<?php

namespace App\Ventas;

use App\Compras\Montos;
use App\Models\ProductoVariante;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuscarProductosMostrador
{
    public const SUGERENCIAS = 8;

    public const POR_PAGINA = 8;

    /**
     * @param  array{
     *     texto?: string,
     *     almacen_id?: int|null,
     *     categoria_id?: int|null,
     *     disponibilidad?: string,
     *     atributos?: array<string, mixed>
     * }  $filtros
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function sugerir(array $filtros): array
    {
        $texto = trim((string) ($filtros['texto'] ?? ''));

        if ($texto === '') {
            return ['items' => [], 'total' => 0];
        }

        $consulta = $this->consulta($filtros);
        $total = (clone $consulta)->count();
        $items = $consulta
            ->orderBy('sku')
            ->orderBy('id')
            ->limit(self::SUGERENCIAS)
            ->get()
            ->map(fn (ProductoVariante $variante): array => $this->mapear($variante, $filtros['almacen_id'] ?? null))
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param  array{
     *     texto?: string,
     *     almacen_id?: int|null,
     *     categoria_id?: int|null,
     *     disponibilidad?: string,
     *     atributos?: array<string, mixed>,
     *     pagina?: int
     * }  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginar(array $filtros): LengthAwarePaginator
    {
        $pagina = max(1, (int) ($filtros['pagina'] ?? 1));

        $paginator = $this->consulta($filtros)
            ->orderBy('sku')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA, ['*'], 'page', $pagina);

        $almacenId = $filtros['almacen_id'] ?? null;

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductoVariante $variante): array => $this->mapear($variante, $almacenId)
            )
        );

        return $paginator;
    }

    public function exacta(string $texto): ?ProductoVariante
    {
        $texto = trim($texto);

        if ($texto === '') {
            return null;
        }

        return ProductoVariante::query()
            ->with(['productoMaestro.unidadBase'])
            ->where('is_active', true)
            ->where(function (Builder $query) use ($texto): void {
                $this->coincidirTexto($query, 'sku', $texto, true);
                $query->orWhere(function (Builder $exacta) use ($texto): void {
                    $this->coincidirTexto($exacta, 'codigo_barras', $texto, true);
                });
            })
            ->orderBy('sku')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array{
     *     texto?: string,
     *     almacen_id?: int|null,
     *     categoria_id?: int|null,
     *     disponibilidad?: string,
     *     atributos?: array<string, mixed>
     * }  $filtros
     * @return Builder<ProductoVariante>
     */
    public function consulta(array $filtros): Builder
    {
        $texto = trim((string) ($filtros['texto'] ?? ''));
        $almacenId = $filtros['almacen_id'] ?? null;
        $categoriaId = $filtros['categoria_id'] ?? null;
        $disponibilidad = (string) ($filtros['disponibilidad'] ?? 'todos');
        $atributos = is_array($filtros['atributos'] ?? null) ? $filtros['atributos'] : [];

        $consulta = ProductoVariante::query()
            ->with([
                'productoMaestro.categoria',
                'productoMaestro.unidadBase',
                'stocks' => function ($stocks) use ($almacenId): void {
                    if ($almacenId) {
                        $stocks->where('almacen_id', $almacenId);
                    }
                },
            ])
            ->where('is_active', true);

        if ($texto !== '') {
            $consulta->where(function (Builder $query) use ($texto): void {
                $this->coincidirTexto($query, 'sku', $texto);
                $query->orWhere(function (Builder $barras) use ($texto): void {
                    $this->coincidirTexto($barras, 'codigo_barras', $texto);
                })->orWhereHas('productoMaestro', function (Builder $maestro) use ($texto): void {
                    $this->coincidirTexto($maestro, 'nombre', $texto);
                });
            });
        }

        if ($categoriaId) {
            $consulta->whereHas('productoMaestro', function (Builder $maestro) use ($categoriaId): void {
                $maestro->where('categoria_id', $categoriaId);
            });
        }

        $this->aplicarDisponibilidad($consulta, $disponibilidad, $almacenId);
        $this->aplicarAtributos($consulta, $atributos);

        return $consulta;
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  Builder<ProductoVariante>  $consulta
     */
    private function aplicarAtributos(Builder $consulta, array $atributos): void
    {
        foreach ($atributos as $clave => $valor) {
            $clave = (string) $clave;
            $valor = is_array($valor) ? '' : trim((string) $valor);

            if ($clave === '' || $valor === '') {
                continue;
            }

            $consulta->where(function (Builder $query) use ($clave, $valor): void {
                $query->where('atributos_json->'.$clave, $valor);

                if (is_numeric($valor)) {
                    $query->orWhere('atributos_json->'.$clave, $valor + 0);
                }
            });
        }
    }

    /**
     * @param  Builder<ProductoVariante>  $consulta
     */
    private function aplicarDisponibilidad(Builder $consulta, string $disponibilidad, mixed $almacenId): void
    {
        if (! $almacenId || ! in_array($disponibilidad, ['con_stock', 'sin_stock'], true)) {
            return;
        }

        $conStock = function (Builder $stocks) use ($almacenId): void {
            $stocks->where('almacen_id', $almacenId)
                ->whereRaw('(cantidad_actual - cantidad_reservada) > 0');
        };

        if ($disponibilidad === 'con_stock') {
            $consulta->whereHas('stocks', $conStock);

            return;
        }

        $consulta->whereDoesntHave('stocks', $conStock);
    }

    /**
     * @return array{id: int, sku: string, nombre: string, precio: string, disponible: string, unidad_simbolo: string, sin_stock: bool, ficha: string}
     */
    public function mapear(ProductoVariante $variante, mixed $almacenId): array
    {
        $disponible = $this->disponibleEn($variante, $almacenId);

        return [
            'id' => (int) $variante->id,
            'sku' => (string) $variante->sku,
            'nombre' => (string) ($variante->productoMaestro?->nombre ?? '—'),
            'precio' => (string) $variante->precio_venta,
            'disponible' => $disponible,
            'unidad_simbolo' => $this->simboloDe($variante),
            'sin_stock' => Montos::comparar($disponible, '0', 4) !== 1,
            'ficha' => $this->ficha($variante),
        ];
    }

    private function simboloDe(ProductoVariante $variante): string
    {
        return (string) ($variante->productoMaestro?->unidadBase?->simbolo ?: 'pza');
    }

    private function disponibleEn(ProductoVariante $variante, mixed $almacenId): string
    {
        if (! $almacenId) {
            return '0.0000';
        }

        $stock = $variante->relationLoaded('stocks')
            ? $variante->stocks->firstWhere('almacen_id', (int) $almacenId)
            : $variante->stocks()->where('almacen_id', $almacenId)->first();

        if (! $stock) {
            return '0.0000';
        }

        return Montos::sumar(
            (string) $stock->cantidad_actual,
            bcmul((string) $stock->cantidad_reservada, '-1', 4),
            4,
        );
    }

    private function ficha(ProductoVariante $variante): string
    {
        $valores = Collection::make($variante->atributos_json ?? [])
            ->filter(fn (mixed $valor): bool => filled($valor) || $valor === 0 || $valor === '0')
            ->map(function (mixed $valor): string {
                if (is_array($valor)) {
                    return implode(', ', $valor);
                }

                return (string) $valor;
            })
            ->filter()
            ->values();

        return $valores->implode(' · ');
    }

    /**
     * @param  Builder<*>  $query
     */
    private function coincidirTexto(Builder $query, string $columna, string $texto, bool $exacto = false): void
    {
        $valor = mb_strtolower($texto);
        $patron = $exacto ? $valor : '%'.$valor.'%';

        $query->whereRaw('LOWER('.$query->qualifyColumn($columna).') LIKE ?', [$patron]);
    }
}
