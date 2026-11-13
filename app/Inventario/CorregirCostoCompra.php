<?php

namespace App\Inventario;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\TipoMovimientoInventario;
use App\Models\CorreccionCosto;
use App\Models\InventarioStock;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CorregirCostoCompra
{
    public function __construct(private RegistrarMovimiento $movimientos, private CostoPromedio $costos) {}

    public function vistaPrevia(OrdenCompraDetalle $detalle, string $costoNuevo): string
    {
        $variante = $this->variante($detalle);

        return $this->revaluar($detalle, $variante, $this->stock($variante), $detalle->costoVigente(), $costoNuevo);
    }

    private function revaluar(OrdenCompraDetalle $detalle, ProductoVariante $variante, string $stock, string $costoAnterior, string $costoNuevo): string
    {
        try {
            return $this->costos->revaluar(
                (string) $variante->precio_costo,
                $stock,
                $costoAnterior,
                $costoNuevo,
                $this->cantidadRecibida($detalle),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['costo_nuevo' => $exception->getMessage()]);
        }
    }

    public function corregir(OrdenCompraDetalle $detalle, string $costoNuevo, string $observacion, TenantUser $usuario): CorreccionCosto
    {
        abort_unless($usuario->can('Corregir:Costo'), 403);
        $observacion = trim($observacion);

        if ($observacion === '') {
            throw ValidationException::withMessages(['observacion' => 'Escribí por qué se corrige el costo.']);
        }

        return DB::transaction(function () use ($detalle, $costoNuevo, $observacion, $usuario): CorreccionCosto {
            $detalle = OrdenCompraDetalle::query()->lockForUpdate()->findOrFail($detalle->id);
            $variante = $this->variante($detalle);
            $variante = ProductoVariante::query()->whereKey($variante->id)->lockForUpdate()->firstOrFail();
            $stock = $this->stock($variante, bloquear: true);
            $costoAnterior = $detalle->costoVigente();
            $promedio = $this->revaluar($detalle, $variante, $stock, $costoAnterior, $costoNuevo);
            $correccion = CorreccionCosto::query()->create([
                'orden_compra_detalle_id' => $detalle->id,
                'producto_variante_id' => $variante->id,
                'almacen_id' => $detalle->orden->almacen_destino_id,
                'user_id' => $usuario->id,
                'supervisor_id' => $usuario->id,
                'costo_anterior' => $costoAnterior,
                'costo_nuevo' => Montos::cuatro($costoNuevo),
                'promedio_anterior' => $variante->precio_costo,
                'promedio_nuevo' => $promedio,
                'observacion' => $observacion,
            ]);
            $this->movimientos->mover([
                'variante' => $variante,
                'almacen_id' => $detalle->orden->almacen_destino_id,
                'user_id' => $usuario->id,
                'tipo' => TipoMovimientoInventario::CorreccionCosto,
                'cantidad' => '0',
                'sentido' => 'costo',
                'costo_unitario' => Montos::cuatro($costoNuevo),
                'costo_promedio_resultante' => $promedio,
                'referencia_tipo' => 'correccion_costo',
                'referencia_id' => $correccion->id,
            ]);

            return $correccion;
        });
    }

    private function variante(OrdenCompraDetalle $detalle): ProductoVariante
    {
        $detalle->loadMissing('orden', 'variante');
        $variante = $detalle->variante;

        if (! $variante instanceof ProductoVariante || $detalle->orden === null) {
            throw ValidationException::withMessages(['costo_nuevo' => 'Esa línea de compra no tiene producto.']);
        }

        if (Montos::comparar((string) $detalle->cantidad_recibida, '0', 4) !== 1) {
            throw ValidationException::withMessages(['costo_nuevo' => 'Esa línea todavía no se recibió.']);
        }

        return $variante;
    }

    private function stock(ProductoVariante $variante, bool $bloquear = false): string
    {
        $total = '0.0000';

        $filas = InventarioStock::query()->where('producto_variante_id', $variante->id);

        if ($bloquear) {
            $filas->lockForUpdate();
        }

        foreach ($filas->get() as $fila) {
            $total = Montos::sumar($total, (string) $fila->cantidad_actual, 4);
        }

        if (Montos::comparar($total, '0', 4) !== 1) {
            throw ValidationException::withMessages(['costo_nuevo' => 'No queda stock de esa compra para corregir.']);
        }

        return $total;
    }

    private function cantidadRecibida(OrdenCompraDetalle $detalle): string
    {
        return Montos::multiplicar((string) $detalle->cantidad_recibida, (string) $detalle->factor_a_base, 4);
    }
}
