<?php

namespace App\Ventas;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimientoInventario;
use App\Models\CajaSesion;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnularVenta
{
    public function __construct(private CostoPromedio $costoPromedio, private CorregirVenta $correcciones) {}

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
            if ($venta->cambioOrigen()->exists()) {
                throw ValidationException::withMessages(['venta' => 'Esta venta corresponde a un reemplazo. Revisá las opciones de cambio o devolución desde su detalle.']);
            }
            if ($venta->devoluciones()->exists()) {
                throw ValidationException::withMessages(['venta' => 'Esta venta tiene devoluciones. Usá el flujo de devolución para los productos restantes.']);
            }

            if (! $venta->estaCompletada() || $venta->tipo_comprobante !== TipoComprobante::NotaVenta) {
                throw ValidationException::withMessages(['venta' => 'Solo se pueden anular notas de venta completadas.']);
            }

            if ($venta->aplicacionesPago()->whereHas('pago', fn ($query) => $query->where('estado_pago', EstadoPago::Pendiente))->exists()) {
                throw ValidationException::withMessages(['venta' => 'La venta tiene pagos pendientes. Revisalos antes de anular.']);
            }

            $cobrado = $venta->cobradoNeto();

            if ($cobrado !== $cobradoConfirmado) {
                throw ValidationException::withMessages(['venta' => 'Los cobros cambiaron. Volvé a abrir la anulación para revisar el monto.']);
            }

            $reembolsos = $this->correcciones->distribuirReembolso($venta, $cobrado);
            if ($reembolsos !== [] && (! $caja instanceof CajaSesion || ! $caja->esDeHoy())) {
                throw ValidationException::withMessages(['venta' => 'Abrí tu caja de hoy en esta sucursal para registrar el reembolso.']);
            }

            $this->reponerInventario($venta, $usuario);

            foreach ($reembolsos as $reembolso) {
                $this->correcciones->registrarDiferencia($venta, $caja, SentidoPago::Reembolso, MetodoPago::from($reembolso['metodo']), $reembolso['monto'], $reembolso['origen']);
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
        $detalles = collect($this->correcciones->lineasVigentes($venta))->map(fn (array $linea): object => (object) $linea);
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
