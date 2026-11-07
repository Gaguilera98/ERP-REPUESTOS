<?php

namespace App\Ventas;

use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\SentidoPago;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\TenantUser;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaldoAFavorCliente
{
    /** @return Builder<PagoAplicacion> */
    private function consulta(int $clienteId): Builder
    {
        return PagoAplicacion::query()->whereNull('venta_id')->whereNull('nota_credito_id')
            ->whereHas('pago', fn (Builder $query): Builder => $query->where('cliente_id', $clienteId)
                ->where('sentido', SentidoPago::Cobro)->where('estado_pago', EstadoPago::Aprobado));
    }

    public function disponible(int $clienteId): string
    {
        return $this->consulta($clienteId)->withSum('usos', 'monto')->get()
            ->reduce(fn (string $total, PagoAplicacion $origen): string => bcadd($total, $this->restante($origen), 2), '0.00');
    }

    private function restante(PagoAplicacion $origen): string
    {
        return bcsub($origen->monto, (string) ($origen->usos_sum_monto ?? '0'), 2);
    }

    /** @return Collection<int, PagoAplicacion> */
    private function bloquear(int $clienteId): Collection
    {
        return $this->consulta($clienteId)->with(['pago', 'aplicacionOrigen'])->orderBy('id')->lockForUpdate()->get()->loadSum('usos', 'monto');
    }

    public function aplicar(Venta $venta, string $monto): void
    {
        validator(['monto' => $monto], ['monto' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'numeric', 'gt:0']])->validate();
        DB::transaction(function () use ($venta, $monto): void {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            Cliente::query()->lockForUpdate()->findOrFail($venta->cliente_id);
            if (bccomp($monto, $venta->saldoPendiente(), 2) > 0) {
                throw ValidationException::withMessages(['saldo_favor' => 'El saldo aplicado supera lo pendiente de la venta.']);
            }
            $origenes = $this->bloquear((int) $venta->cliente_id);
            $this->validarDisponible($origenes, $monto);
            foreach ($origenes as $origen) {
                $restante = $this->restante($origen);
                if ($monto === '0.00' || bccomp($restante, '0', 2) <= 0) {
                    continue;
                }
                $importe = bccomp($monto, $restante, 2) > 0 ? $restante : $monto;
                $venta->aplicacionesPago()->create([
                    'pago_id' => $origen->pago_id, 'aplicacion_origen_id' => $origen->id,
                    'es_saldo_favor' => true, 'monto' => $importe,
                    'reserva_saldo_origen_id' => $origen->reserva_saldo_origen_id,
                ]);
                $monto = bcsub($monto, $importe, 2);
            }
        });
    }

    private function validarDisponible(Collection $origenes, string $monto): string
    {
        $disponible = $origenes->reduce(fn (string $total, PagoAplicacion $origen): string => bcadd($total, $this->restante($origen), 2), '0.00');
        if (bccomp($monto, $disponible, 2) > 0) {
            throw ValidationException::withMessages(['saldo_favor' => 'El saldo a favor disponible cambió o es insuficiente. Revisá el importe.']);
        }

        return $disponible;
    }

    public function reembolsar(Venta $reserva, string $monto, string $motivo, TenantUser $usuario, string $disponibleConfirmado): void
    {
        abort_unless($usuario->can('Reembolsar:Sena') && $usuario->can('view', $reserva), 403);
        $motivo = trim($motivo);
        validator(['monto' => $monto, 'motivo' => $motivo], [
            'monto' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'motivo' => ['required', 'string', 'max:500'],
        ])->validate();
        $monto = bcadd($monto, '0', 2);
        app(GestionarReservas::class)->procesarVencidas();

        DB::transaction(function () use ($reserva, $monto, $motivo, $usuario, $disponibleConfirmado): void {
            $caja = CajaSesion::query()->where('user_id', $usuario->id)->where('sucursal_id', $reserva->sucursal_id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)->whereDate('fecha_apertura', now()->toDateString())->lockForUpdate()->first();
            $reserva = Venta::query()->lockForUpdate()->findOrFail($reserva->id);
            if ($reserva->estado !== EstadoVenta::Vencida || ! $reserva->sena_reembolsable) {
                throw ValidationException::withMessages(['monto' => 'Esta seña no está habilitada para reembolso.']);
            }
            if (! $caja) {
                throw ValidationException::withMessages(['monto' => 'Abrí tu caja de hoy en la sucursal de la reserva.']);
            }
            Cliente::query()->lockForUpdate()->findOrFail($reserva->cliente_id);
            $origenes = $this->bloquear($reserva->cliente_id)->filter(
                fn (PagoAplicacion $origen): bool => (int) $origen->reserva_saldo_origen_id === $reserva->id,
            );
            if ($this->validarDisponible($origenes, $monto) !== $disponibleConfirmado) {
                throw ValidationException::withMessages(['monto' => 'El saldo cambió. Volvé a abrir el reembolso para revisarlo.']);
            }
            foreach ($origenes as $origen) {
                $restante = $this->restante($origen);
                if ($monto === '0.00' || bccomp($restante, '0', 2) <= 0) {
                    continue;
                }
                $importe = bccomp($monto, $restante, 2) > 0 ? $restante : $monto;
                $pago = Pago::query()->create([
                    'cliente_id' => $reserva->cliente_id, 'caja_sesion_id' => $caja->id,
                    'sentido' => SentidoPago::Reembolso, 'metodo_pago' => $origen->pago->metodo_pago,
                    'monto' => $importe, 'estado_pago' => EstadoPago::Aprobado, 'fecha_pago' => now(),
                    'registrado_por_id' => $usuario->id, 'motivo' => $motivo,
                ]);
                $pago->aplicaciones()->create(['venta_id' => $reserva->id, 'aplicacion_origen_id' => $origen->id, 'monto' => $importe]);
                $monto = bcsub($monto, $importe, 2);
            }
        }, attempts: 3);
    }

    public function disponibleDeReserva(Venta $reserva): string
    {
        return $this->consulta((int) $reserva->cliente_id)->where('reserva_saldo_origen_id', $reserva->id)
            ->withSum('usos', 'monto')->get()->reduce(fn (string $total, PagoAplicacion $origen): string => bcadd($total, $this->restante($origen), 2), '0.00');
    }
}
