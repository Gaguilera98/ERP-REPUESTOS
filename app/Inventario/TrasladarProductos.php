<?php

namespace App\Inventario;

use App\Compras\Montos;
use App\Enums\TipoMovimientoInventario;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Traslado;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrasladarProductos
{
    public function __construct(private RegistrarMovimiento $movimientos) {}

    /**
     * @param  list<array{producto_variante_id: mixed, cantidad: mixed}>  $lineas
     */
    public function trasladar(int $origenId, int $destinoId, array $lineas, TenantUser $usuario, ?string $observacion = null): Traslado
    {
        abort_unless($usuario->can('Trasladar:Inventario'), 403);

        if ($origenId === $destinoId) {
            throw ValidationException::withMessages(['destino' => 'El destino tiene que ser otro almacén.']);
        }

        if ($lineas === []) {
            throw ValidationException::withMessages(['lineas' => 'Elegí al menos una pieza.']);
        }

        return DB::transaction(function () use ($origenId, $destinoId, $lineas, $usuario, $observacion): Traslado {
            $this->movimientos->exigirLibre($origenId);
            $this->movimientos->exigirLibre($destinoId);
            $traslado = Traslado::query()->create([
                'almacen_origen_id' => $origenId,
                'almacen_destino_id' => $destinoId,
                'user_id' => $usuario->id,
                'estado' => 'completado',
                'observacion' => filled($observacion) ? trim($observacion) : null,
            ]);

            foreach ($lineas as $linea) {
                $variante = ProductoVariante::query()->find($linea['producto_variante_id'] ?? null);

                if (! $variante instanceof ProductoVariante) {
                    throw ValidationException::withMessages(['lineas' => 'Elegí un producto del negocio.']);
                }

                $cantidad = Montos::cuatro((string) ($linea['cantidad'] ?? '0'));

                if (Montos::comparar($cantidad, '0', 4) !== 1) {
                    throw ValidationException::withMessages(['lineas' => 'La cantidad de '.$variante->sku.' tiene que ser mayor a cero.']);
                }

                $traslado->detalles()->create([
                    'producto_variante_id' => $variante->id,
                    'cantidad' => $cantidad,
                ]);
                $this->movimientos->mover([
                    'variante' => $variante,
                    'almacen_id' => $origenId,
                    'user_id' => $usuario->id,
                    'tipo' => TipoMovimientoInventario::TrasladoSalida,
                    'cantidad' => $cantidad,
                    'sentido' => 'salida',
                    'costo_unitario' => (string) $variante->precio_costo,
                    'costo_promedio_resultante' => (string) $variante->precio_costo,
                    'referencia_tipo' => 'traslado',
                    'referencia_id' => $traslado->id,
                ]);
                $this->movimientos->mover([
                    'variante' => $variante,
                    'almacen_id' => $destinoId,
                    'user_id' => $usuario->id,
                    'tipo' => TipoMovimientoInventario::TrasladoEntrada,
                    'cantidad' => $cantidad,
                    'sentido' => 'entrada',
                    'costo_unitario' => (string) $variante->precio_costo,
                    'costo_promedio_resultante' => (string) $variante->precio_costo,
                    'referencia_tipo' => 'traslado',
                    'referencia_id' => $traslado->id,
                ]);
            }

            return $traslado->load('detalles');
        });
    }
}
