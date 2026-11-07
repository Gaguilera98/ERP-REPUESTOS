<?php

namespace App\Ventas;

use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoMovimientoInventario;
use App\Models\CajaSesion;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\Pago;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GestionarReservas
{
    public function procesarVencidas(?int $sucursalId = null): void
    {
        Venta::query()->where('estado', EstadoVenta::Reserva)->where('vence_en', '<=', now())
            ->when($sucursalId !== null, fn ($query) => $query->where('sucursal_id', $sucursalId))
            ->chunkById(100, function ($ventas): void {
                foreach ($ventas as $venta) {
                    $this->vencer($venta);
                }
            });
    }

    public function vencer(Venta $venta): void
    {
        DB::transaction(function () use ($venta): void {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            if ($venta->estado !== EstadoVenta::Reserva || $venta->vence_en->isFuture()) {
                return;
            }
            $this->liberar($venta);
            foreach ($venta->aplicacionesPago()->with('pago')->get() as $origen) {
                if ($origen->pago->estado_pago === EstadoPago::Aprobado && $origen->pago->sentido === SentidoPago::Cobro) {
                    $origen->pago->aplicaciones()->create([
                        'venta_id' => null, 'aplicacion_origen_id' => $origen->id, 'monto' => $origen->monto,
                        'reserva_saldo_origen_id' => $venta->id,
                    ]);
                }
            }
            $venta->estado = EstadoVenta::Vencida;
            $venta->save();
        }, attempts: 3);
    }

    public function entregar(Venta $venta, string $saldoConfirmado, TenantUser $usuario): void
    {
        abort_unless($usuario->can('create', Venta::class) && $usuario->can('view', $venta), 403);
        $this->procesarVencidas();

        DB::transaction(function () use ($venta, $saldoConfirmado, $usuario): void {
            $caja = CajaSesion::query()->where('user_id', $usuario->id)->where('sucursal_id', $venta->sucursal_id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)->whereDate('fecha_apertura', now()->toDateString())
                ->lockForUpdate()->first();
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            abort_unless($usuario->can('create', Venta::class) && $usuario->can('view', $venta), 403);
            if ($venta->estado !== EstadoVenta::Reserva || ! $venta->vence_en->isFuture()) {
                throw ValidationException::withMessages(['venta' => 'La reserva venció o ya fue entregada.']);
            }
            if (! $caja) {
                throw ValidationException::withMessages(['venta' => 'Abrí tu caja de hoy en esta sucursal.']);
            }
            $saldo = $venta->saldoPendiente();
            if ($saldo !== $saldoConfirmado) {
                throw ValidationException::withMessages(['venta' => 'El saldo cambió. Volvé a revisar la reserva.']);
            }

            $detalles = $venta->detalles()->orderBy('producto_variante_id')->orderBy('id')->get();
            $variantes = ProductoVariante::query()->whereKey($detalles->pluck('producto_variante_id')->unique()->values()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($detalles as $detalle) {
                $detalle->precio_costo_snapshot = $variantes->get($detalle->producto_variante_id)->precio_costo;
                $detalle->save();
                $stock = InventarioStock::query()->where('producto_variante_id', $detalle->producto_variante_id)
                    ->where('almacen_id', $venta->almacen_id)->lockForUpdate()->firstOrFail();
                $previo = $stock->cantidad_actual;
                $stock->cantidad_actual = bcsub($previo, $detalle->cantidad_base, 4);
                $stock->cantidad_reservada = bcsub($stock->cantidad_reservada, $detalle->cantidad_base, 4);
                $stock->save();
                MovimientoInventario::query()->create([
                    'producto_variante_id' => $detalle->producto_variante_id, 'almacen_id' => $venta->almacen_id, 'user_id' => $usuario->id,
                    'tipo_movimiento' => TipoMovimientoInventario::SalidaVenta, 'cantidad' => $detalle->cantidad_base,
                    'stock_previo' => $previo, 'stock_posterior' => $stock->cantidad_actual,
                    'costo_unitario' => $detalle->precio_costo_snapshot, 'costo_promedio_resultante' => $detalle->precio_costo_snapshot,
                    'referencia_tipo' => MovimientoInventario::REFERENCIA_VENTA, 'referencia_id' => $venta->id,
                ]);
            }
            if (bccomp($saldo, '0', 2) > 0) {
                $pago = Pago::query()->create([
                    'cliente_id' => $venta->cliente_id, 'caja_sesion_id' => $caja->id, 'sentido' => SentidoPago::Cobro,
                    'metodo_pago' => MetodoPago::Efectivo, 'monto' => $saldo, 'estado_pago' => EstadoPago::Aprobado, 'fecha_pago' => now(),
                ]);
                $pago->aplicaciones()->create(['venta_id' => $venta->id, 'monto' => $saldo]);
            }
            $venta->estado = EstadoVenta::Completada;
            $venta->save();
        }, attempts: 3);
    }

    private function liberar(Venta $venta): void
    {
        foreach ($venta->detalles()->orderBy('producto_variante_id')->orderBy('id')->get() as $detalle) {
            $stock = InventarioStock::query()->where('producto_variante_id', $detalle->producto_variante_id)
                ->where('almacen_id', $venta->almacen_id)->lockForUpdate()->firstOrFail();
            $stock->cantidad_reservada = bcsub($stock->cantidad_reservada, $detalle->cantidad_base, 4);
            $stock->save();
        }
    }
}
