<?php

namespace App\Inventario;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\EstadoAjusteInventario;
use App\Enums\TipoAjusteInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\AjusteInventario;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoVariante;
use Illuminate\Validation\ValidationException;

class RegistrarMovimiento
{
    public const MENSAJE_CONTEO = 'Este almacén tiene un conteo abierto. Cerrá el conteo para vender, reservar, trasladar o recibir mercadería.';

    public function __construct(private CostoPromedio $costos) {}

    public function exigirLibre(int $almacenId, ?string $referenciaTipo = null, ?int $referenciaId = null): void
    {
        $abierto = AjusteInventario::query()
            ->where('almacen_id', $almacenId)
            ->where('tipo', TipoAjusteInventario::Conteo)
            ->where('estado', EstadoAjusteInventario::EnCurso)
            ->first();

        if ($abierto instanceof AjusteInventario
            && ! ($referenciaTipo === 'ajuste' && $referenciaId === $abierto->id)) {
            throw ValidationException::withMessages(['almacen' => self::MENSAJE_CONTEO]);
        }
    }

    public function reservar(InventarioStock $stock, string $delta): void
    {
        $this->exigirLibre($stock->almacen_id);
        $locked = InventarioStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $reservada = Montos::sumar((string) $locked->cantidad_reservada, $delta, 4);

        if (Montos::comparar($reservada, '0', 4) === -1 || Montos::comparar($reservada, (string) $locked->cantidad_actual, 4) === 1) {
            throw ValidationException::withMessages(['cantidad' => 'No se puede reservar más de lo que hay.']);
        }

        $locked->cantidad_reservada = $reservada;
        $locked->save();
        $stock->cantidad_reservada = $reservada;
        $stock->cantidad_actual = $locked->cantidad_actual;
    }

    /**
     * @param  array{
     *     variante: ProductoVariante,
     *     almacen_id: int,
     *     user_id: int,
     *     tipo: TipoMovimientoInventario,
     *     cantidad: string,
     *     sentido: 'entrada'|'salida'|'costo',
     *     costo_unitario: string,
     *     costo_promedio_resultante?: string|null,
     *     recalcular_promedio?: bool,
     *     delta_reservada?: string|null,
     *     referencia_tipo: string,
     *     referencia_id: int
     * }  $datos
     */
    public function mover(array $datos): MovimientoInventario
    {
        $this->exigirLibre($datos['almacen_id'], $datos['referencia_tipo'], $datos['referencia_id']);

        $variante = ProductoVariante::query()->whereKey($datos['variante']->id)->lockForUpdate()->firstOrFail();
        $stock = InventarioStock::query()
            ->where('producto_variante_id', $variante->id)
            ->where('almacen_id', $datos['almacen_id'])
            ->lockForUpdate()
            ->first();

        if (! $stock instanceof InventarioStock && $datos['sentido'] === 'entrada') {
            $stock = InventarioStock::query()->create([
                'producto_variante_id' => $variante->id,
                'almacen_id' => $datos['almacen_id'],
            ]);
            $stock = InventarioStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }

        if (! $stock instanceof InventarioStock) {
            throw ValidationException::withMessages(['almacen' => 'Ese almacén no tiene saldo para este producto.']);
        }

        $cantidad = Montos::cuatro($datos['cantidad']);
        $previo = Montos::cuatro((string) $stock->cantidad_actual);

        if ($datos['sentido'] === 'costo') {
            $promedio = Montos::cuatro((string) $datos['costo_promedio_resultante']);
            $variante->precio_costo = $promedio;
            $variante->save();
            $datos['variante']->precio_costo = $promedio;

            return $this->linea($datos, $variante, $stock, '0.0000', $previo, $previo, $promedio);
        }

        if (Montos::comparar($cantidad, '0', 4) !== 1) {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad tiene que ser mayor a cero.']);
        }

        $posterior = $datos['sentido'] === 'salida'
            ? Montos::sumar($previo, bcmul($cantidad, '-1', 4), 4)
            : Montos::sumar($previo, $cantidad, 4);

        if (Montos::comparar($posterior, '0', 4) === -1) {
            throw ValidationException::withMessages(['cantidad' => 'El stock no puede quedar negativo.']);
        }

        $reservada = Montos::sumar((string) $stock->cantidad_reservada, $datos['delta_reservada'] ?? '0', 4);

        if (Montos::comparar($reservada, '0', 4) === -1 || Montos::comparar($posterior, $reservada, 4) === -1) {
            throw ValidationException::withMessages(['cantidad' => 'El stock quedaría por debajo de lo reservado.']);
        }

        $promedio = Montos::cuatro((string) ($datos['costo_promedio_resultante'] ?? $datos['costo_unitario']));

        if ($datos['recalcular_promedio'] ?? false) {
            $total = '0.0000';

            foreach (InventarioStock::query()->where('producto_variante_id', $variante->id)->lockForUpdate()->get() as $fila) {
                $total = Montos::sumar($total, (string) $fila->cantidad_actual, 4);
            }

            $promedio = $this->costos->resultante((string) $variante->precio_costo, $total, $datos['costo_unitario'], $cantidad);
            $variante->precio_costo = $promedio;
            $variante->save();
        }

        $stock->cantidad_actual = $posterior;
        $stock->cantidad_reservada = $reservada;
        $stock->save();
        $datos['variante']->precio_costo = $variante->precio_costo;

        return $this->linea($datos, $variante, $stock, $cantidad, $previo, $posterior, $promedio);
    }

    /**
     * @param  array{user_id: int, tipo: TipoMovimientoInventario, costo_unitario: string, referencia_tipo: string, referencia_id: int}  $datos
     */
    private function linea(array $datos, ProductoVariante $variante, InventarioStock $stock, string $cantidad, string $previo, string $posterior, string $promedio): MovimientoInventario
    {
        return MovimientoInventario::query()->create([
            'producto_variante_id' => $variante->id,
            'almacen_id' => $stock->almacen_id,
            'user_id' => $datos['user_id'],
            'tipo_movimiento' => $datos['tipo'],
            'cantidad' => $cantidad,
            'stock_previo' => $previo,
            'stock_posterior' => $posterior,
            'costo_unitario' => Montos::cuatro($datos['costo_unitario']),
            'costo_promedio_resultante' => $promedio,
            'referencia_tipo' => $datos['referencia_tipo'],
            'referencia_id' => $datos['referencia_id'],
        ]);
    }
}
