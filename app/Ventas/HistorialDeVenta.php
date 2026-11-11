<?php

namespace App\Ventas;

use App\Compras\Montos;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\SentidoPago;
use App\Models\NotaCreditoDetalle;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

class HistorialDeVenta
{
    /**
     * @param  Collection<int, stdClass>  $ampliaciones
     * @return Collection<int, array{fecha: CarbonInterface, titulo: string, icono: string, responsable: ?string, motivo: ?string, detalles: list<string>}>
     */
    public function eventos(Venta $venta, Collection $ampliaciones, string $moneda): Collection
    {
        $eventos = collect();
        $agregar = function (?CarbonInterface $fecha, string $titulo, string $icono, ?string $responsable = null, ?string $motivo = null, array $detalles = []) use ($eventos): void {
            if ($fecha !== null) {
                $eventos->push(compact('fecha', 'titulo', 'icono', 'responsable', 'motivo', 'detalles'));
            }
        };

        $esReserva = $venta->vence_en !== null || $venta->estado === EstadoVenta::Reserva;

        if ($venta->anulada_en !== null) {
            $agregar($venta->anulada_en, $esReserva && $venta->entregada_en === null ? 'Reserva cancelada' : 'Venta anulada', 'heroicon-o-x-circle', $venta->anuladaPor?->name, $venta->motivo_anulacion);
        }

        $reembolsosPorDevolucion = [];
        foreach ($venta->aplicacionesPago as $aplicacion) {
            $pago = $aplicacion->pago;
            if ($pago?->estado_pago === EstadoPago::Aprobado && $pago->sentido === SentidoPago::Reembolso && $aplicacion->nota_credito_id !== null) {
                $notaId = $aplicacion->nota_credito_id;
                $reembolsosPorDevolucion[$notaId] = bcadd($reembolsosPorDevolucion[$notaId] ?? '0', $aplicacion->monto, 2);
            }
        }

        foreach ($venta->devoluciones as $devolucion) {
            $detalles = $devolucion->detalles->map(fn (NotaCreditoDetalle $linea): string => trim(Montos::sinCeros($linea->cantidad).' '.($linea->linea_snapshot['unidad'] ?? '')).' · '.$linea->linea_snapshot['sku'])->all();
            $reembolso = $reembolsosPorDevolucion[$devolucion->id] ?? '0.00';
            $saldoAFavor = bcsub($devolucion->importe_resuelto, $reembolso, 2);

            if (bccomp($reembolso, '0', 2) > 0) {
                $detalles[] = "Reembolso de {$moneda} {$reembolso}";
            }
            if (bccomp($saldoAFavor, '0', 2) > 0) {
                $detalles[] = "Saldo a favor de {$moneda} {$saldoAFavor}";
            }
            if (bccomp($devolucion->deuda_cancelada, '0', 2) > 0) {
                $detalles[] = "Deuda reducida en {$moneda} {$devolucion->deuda_cancelada}";
            }
            if (filled($devolucion->motivo_excepcion)) {
                $detalles[] = 'Excepción al plazo · '.$devolucion->motivo_excepcion;
            }

            $agregar($devolucion->created_at, 'Devolución registrada', 'heroicon-o-arrow-uturn-left', $devolucion->usuario?->name, $devolucion->motivo, $detalles);
        }

        foreach ($venta->correcciones as $correccion) {
            $agregar($correccion->created_at, 'Venta corregida', 'heroicon-o-pencil-square', $correccion->usuario?->name, $correccion->motivo, ["Total corregido {$moneda} {$correccion->total}"]);
        }

        foreach ($ampliaciones as $ampliacion) {
            $anterior = Carbon::parse($ampliacion->vencimiento_anterior)->format('d/m/Y H:i');
            $nuevo = Carbon::parse($ampliacion->vencimiento_nuevo)->format('d/m/Y H:i');
            $agregar(Carbon::parse($ampliacion->created_at), 'Reserva ampliada', 'heroicon-o-calendar-days', $ampliacion->usuario_nombre, $ampliacion->motivo, ["Vencimiento del {$anterior} al {$nuevo}"]);
        }

        if ($venta->estado === EstadoVenta::Vencida) {
            $agregar($venta->vence_en, 'Reserva vencida', 'heroicon-o-clock');
        }
        if ($esReserva) {
            $agregar($venta->entregada_en, 'Reserva entregada', 'heroicon-o-check-circle');
        }

        foreach ($venta->aplicacionesPago as $aplicacion) {
            $pago = $aplicacion->pago;
            if ($pago?->estado_pago !== EstadoPago::Aprobado || $aplicacion->nota_credito_id !== null) {
                continue;
            }

            $titulo = match (true) {
                $aplicacion->es_saldo_favor => 'Saldo a favor aplicado',
                $pago->sentido === SentidoPago::Reembolso => 'Reembolso registrado',
                default => 'Cobro registrado',
            };
            $medio = $aplicacion->es_saldo_favor ? '' : ' · '.$pago->metodo_pago->etiqueta();
            $agregar(
                $aplicacion->es_saldo_favor ? $aplicacion->created_at : ($pago->fecha_pago ?? $pago->created_at),
                $titulo,
                'heroicon-o-banknotes',
                $aplicacion->es_saldo_favor ? null : $pago->registradoPor?->name,
                $aplicacion->es_saldo_favor ? null : $pago->motivo,
                ["{$moneda} {$aplicacion->monto}{$medio}"],
            );
        }

        $agregar($venta->created_at, $esReserva ? 'Reserva creada' : 'Venta registrada', 'heroicon-o-document-text', $venta->usuario?->name);

        return $eventos->sortByDesc(fn (array $evento): int => $evento['fecha']->getTimestamp())->values();
    }
}
