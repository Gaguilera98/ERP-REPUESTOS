<?php

namespace App\Compras;

use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoMovimientoInventario;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecibirMercaderia
{
    public function __construct(private CostoPromedio $costoPromedio) {}

    /**
     * Escribe stock y kardex recién cuando el costo por unidad fue confirmado.
     * El precio de venta de la variante no se toca.
     *
     * @param  list<array{detalle_id: int|string, cantidad: int|string}>  $lineas
     */
    public function recibir(OrdenCompra $orden, array $lineas, TenantUser $usuario, bool $confirmoCostos): OrdenCompra
    {
        if (! $confirmoCostos) {
            throw ValidationException::withMessages([
                'confirmo_costos' => 'Confirmá el costo por unidad antes de recibir. Si no confirmás, no se guarda.',
            ]);
        }

        $pedidas = $this->cantidades($lineas);

        if ($pedidas === []) {
            $primero = collect($lineas)->first();
            $campo = is_array($primero) ? 'cantidad_'.($primero['detalle_id'] ?? '0') : 'confirmo_costos';

            throw ValidationException::withMessages([
                $campo => 'La cantidad que llega tiene que ser mayor a cero.',
            ]);
        }

        return DB::transaction(function () use ($orden, $usuario, $pedidas): OrdenCompra {
            $orden = OrdenCompra::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estado->puedeRecibir()) {
                throw ValidationException::withMessages([
                    'confirmo_costos' => 'Esta compra no está para recibir.',
                ]);
            }

            $detalles = OrdenCompraDetalle::query()
                ->with('unidad')
                ->where('orden_compra_id', $orden->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($pedidas as $detalleId => $cantidad) {
                $detalle = $detalles->get($detalleId);

                if (! $detalle instanceof OrdenCompraDetalle) {
                    throw ValidationException::withMessages([
                        'cantidad_'.$detalleId => 'Esa línea no es de esta compra.',
                    ]);
                }

                $unidad = $detalle->unidad;

                if ($unidad instanceof UnidadMedida && ! $unidad->admiteCantidad($cantidad)) {
                    throw ValidationException::withMessages([
                        'cantidad_'.$detalleId => 'Esa unidad no acepta fracciones.',
                    ]);
                }

                $falta = bcsub((string) $detalle->cantidad_pedida, (string) $detalle->cantidad_recibida, 4);

                if (Montos::comparar($cantidad, $falta) === 1) {
                    throw ValidationException::withMessages([
                        'cantidad_'.$detalleId => 'No podés recibir más de lo que falta.',
                    ]);
                }
            }

            $varianteIds = $detalles
                ->whereIn('id', array_keys($pedidas))
                ->pluck('producto_variante_id')
                ->unique()
                ->sort()
                ->values();

            $variantes = ProductoVariante::query()
                ->whereIn('id', $varianteIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($variantes as $variante) {
                InventarioStock::query()->firstOrCreate([
                    'producto_variante_id' => $variante->id,
                    'almacen_id' => $orden->almacen_destino_id,
                ]);
            }

            $stocks = InventarioStock::query()
                ->whereIn('producto_variante_id', $varianteIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pedidas as $detalleId => $cantidad) {
                $detalle = $detalles->get($detalleId);
                $variante = $variantes->get($detalle->producto_variante_id);

                if (! $variante instanceof ProductoVariante) {
                    throw ValidationException::withMessages([
                        'cantidad_'.$detalleId => 'Ese producto no es de este negocio.',
                    ]);
                }

                $destino = $stocks->first(fn (InventarioStock $stock): bool => $stock->producto_variante_id === $variante->id
                    && $stock->almacen_id === $orden->almacen_destino_id);

                if (! $destino instanceof InventarioStock) {
                    throw ValidationException::withMessages([
                        'cantidad_'.$detalleId => 'Ese almacén no tiene saldo para este producto.',
                    ]);
                }

                $stockTotal = $this->sumarStock($stocks->where('producto_variante_id', $variante->id)->pluck('cantidad_actual'));
                $previo = Montos::redondear((string) $destino->cantidad_actual, 4);
                $enBase = Montos::multiplicar($cantidad, (string) $detalle->factor_a_base, 4);
                $posterior = Montos::sumar($previo, $enBase, 4);
                $promedio = $this->costoPromedio->resultante(
                    (string) $variante->precio_costo,
                    $stockTotal,
                    (string) $detalle->costo_unitario,
                    $enBase,
                );

                $destino->cantidad_actual = $posterior;
                $destino->save();

                $variante->precio_costo = $promedio;
                $variante->save();

                MovimientoInventario::query()->create([
                    'producto_variante_id' => $variante->id,
                    'almacen_id' => $orden->almacen_destino_id,
                    'user_id' => $usuario->id,
                    'tipo_movimiento' => TipoMovimientoInventario::EntradaCompra,
                    'cantidad' => $enBase,
                    'stock_previo' => $previo,
                    'stock_posterior' => $posterior,
                    'costo_unitario' => $detalle->costo_unitario,
                    'costo_promedio_resultante' => $promedio,
                    'referencia_tipo' => MovimientoInventario::REFERENCIA_ORDEN_COMPRA,
                    'referencia_id' => $orden->id,
                ]);

                $detalle->cantidad_recibida = Montos::sumar((string) $detalle->cantidad_recibida, $cantidad, 4);
                $detalle->save();
            }

            $orden->load('detalles');
            $completa = $orden->detalles->every(
                fn (OrdenCompraDetalle $detalle): bool => Montos::comparar($detalle->cantidad_recibida, $detalle->cantidad_pedida) === 0,
            );
            $orden->estado = $completa ? EstadoOrdenCompra::Completada : EstadoOrdenCompra::RecibidaParcial;
            $orden->fecha_recepcion = now();
            $orden->save();

            return $orden->refresh()->load('detalles');
        });
    }

    /**
     * @param  list<array{detalle_id: int|string, cantidad: int|string}>  $lineas
     * @return array<int, string>
     */
    private function cantidades(array $lineas): array
    {
        $pedidas = [];

        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $detalleId = is_int($linea['detalle_id'] ?? null)
                ? $linea['detalle_id']
                : (is_string($linea['detalle_id'] ?? null) ? (int) $linea['detalle_id'] : 0);
            $cantidad = $linea['cantidad'] ?? null;
            $texto = is_int($cantidad) ? (string) $cantidad : (is_string($cantidad) ? trim($cantidad) : null);

            $texto = is_float($cantidad) ? number_format($cantidad, 4, '.', '') : $texto;

            if ($detalleId < 1 || $texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
                throw ValidationException::withMessages([
                    'cantidad_'.$detalleId => 'La cantidad que llega tiene que ser un número de cero para arriba.',
                ]);
            }

            if (Montos::comparar($texto, '0') === 0) {
                continue;
            }

            $pedidas[$detalleId] = Montos::redondear($texto, 4);
        }

        return $pedidas;
    }

    /**
     * @param  Collection<int, mixed>  $cantidades
     */
    private function sumarStock(Collection $cantidades): string
    {
        $total = '0.0000';

        foreach ($cantidades as $cantidad) {
            $total = Montos::sumar($total, (string) $cantidad, 4);
        }

        return $total;
    }
}
