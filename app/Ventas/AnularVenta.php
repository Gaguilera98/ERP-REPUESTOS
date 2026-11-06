<?php

namespace App\Ventas;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
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

class AnularVenta
{
    public function __construct(private CostoPromedio $costoPromedio) {}

    public function anular(Venta $venta, string $motivo, string $cobradoConfirmado, TenantUser $usuario): void
    {
        $motivo = trim($motivo);
        validator(['motivo' => $motivo], ['motivo' => ['required', 'string', 'max:500']])->validate();

        DB::transaction(function () use ($venta, $motivo, $cobradoConfirmado, $usuario): void {
            $caja = CajaSesion::query()
                ->where('sucursal_id', $venta->sucursal_id)
                ->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)
                ->whereDate('fecha_apertura', now()->toDateString())
                ->lockForUpdate()
                ->first();

            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            abort_unless($usuario->can('anular', $venta), 403);

            if (! $venta->estaCompletada() || $venta->tipo_comprobante !== TipoComprobante::NotaVenta) {
                throw ValidationException::withMessages(['venta' => 'Solo se pueden anular notas de venta completadas.']);
            }

            $aplicaciones = $venta->aplicacionesPago()->with('pago')->get();
            if ($aplicaciones->contains(fn ($aplicacion): bool => $aplicacion->pago->estado_pago === EstadoPago::Pendiente
                || $aplicacion->pago->sentido === SentidoPago::Reembolso)) {
                throw ValidationException::withMessages(['venta' => 'La venta tiene pagos pendientes o reembolsos previos. Revisalos antes de anular.']);
            }

            $cobros = $aplicaciones->filter(fn ($aplicacion): bool => $aplicacion->pago->estado_pago === EstadoPago::Aprobado);
            $cobrado = bcsub((string) $venta->total, $venta->saldoPendiente(), 2);

            if ($cobrado !== $cobradoConfirmado) {
                throw ValidationException::withMessages(['venta' => 'Los cobros cambiaron. Volvé a abrir la anulación para revisar el monto.']);
            }

            if ($cobros->isNotEmpty() && (! $caja instanceof CajaSesion || ! $caja->esDeHoy())) {
                throw ValidationException::withMessages(['venta' => 'Abrí tu caja de hoy en esta sucursal para registrar el reembolso.']);
            }

            $this->reponerInventario($venta, $usuario);

            foreach ($cobros as $cobro) {
                $reembolso = Pago::query()->create([
                    'cliente_id' => $venta->cliente_id,
                    'caja_sesion_id' => $caja->id,
                    'sentido' => SentidoPago::Reembolso,
                    'metodo_pago' => $cobro->pago->metodo_pago,
                    'monto' => $cobro->monto,
                    'estado_pago' => EstadoPago::Aprobado,
                    'fecha_pago' => now(),
                ]);

                $reembolso->aplicaciones()->create([
                    'venta_id' => $venta->id,
                    'aplicacion_origen_id' => $cobro->id,
                    'monto' => $cobro->monto,
                ]);
            }

            $venta->estado = EstadoVenta::Anulada;
            $venta->anulada_por_id = $usuario->id;
            $venta->anulada_en = now();
            $venta->motivo_anulacion = $motivo;
            $venta->save();
        }, attempts: 3);
    }

    private function reponerInventario(Venta $venta, TenantUser $usuario): void
    {
        $detalles = $venta->detalles()->orderBy('id')->get();
        $variantes = ProductoVariante::query()
            ->whereKey($detalles->pluck('producto_variante_id')->unique()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $stocks = InventarioStock::query()
            ->whereIn('producto_variante_id', $variantes->keys())
            ->orderBy('id')->lockForUpdate()->get()->groupBy('producto_variante_id');

        foreach ($detalles as $detalle) {
            $variante = $variantes->get($detalle->producto_variante_id);
            $saldos = $stocks->get($detalle->producto_variante_id);
            $destino = $saldos?->firstWhere('almacen_id', $venta->almacen_id);

            if (! $variante instanceof ProductoVariante || ! $destino instanceof InventarioStock) {
                throw ValidationException::withMessages(['venta' => 'Falta el saldo de inventario de un producto en el almacén original.']);
            }

            $stockTotal = $saldos->reduce(
                fn (string $total, InventarioStock $stock): string => Montos::sumar($total, $stock->cantidad_actual, 4),
                '0.0000',
            );
            $previo = $destino->cantidad_actual;
            $posterior = Montos::sumar($previo, $detalle->cantidad_base, 4);
            $promedio = $this->costoPromedio->resultante(
                $variante->precio_costo, $stockTotal, $detalle->precio_costo_snapshot, $detalle->cantidad_base,
            );

            $destino->cantidad_actual = $posterior;
            $destino->save();
            $variante->precio_costo = $promedio;
            $variante->save();

            MovimientoInventario::query()->create([
                'producto_variante_id' => $variante->id,
                'almacen_id' => $venta->almacen_id,
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoInventario::Devolucion,
                'cantidad' => $detalle->cantidad_base,
                'stock_previo' => $previo,
                'stock_posterior' => $posterior,
                'costo_unitario' => $detalle->precio_costo_snapshot,
                'costo_promedio_resultante' => $promedio,
                'referencia_tipo' => MovimientoInventario::REFERENCIA_VENTA,
                'referencia_id' => $venta->id,
            ]);
        }
    }
}
