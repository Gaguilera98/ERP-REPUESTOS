<?php

namespace App\CajaChica;

use App\Enums\EstadoCajaSesion;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\CategoriaGasto;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AutorizarOrigen
{
    public function __construct(private AccesoSucursales $acceso) {}

    public function sesionParaSalida(CajaSesion $sesion, TenantUser $actor): void
    {
        $this->sucursalAsignada($actor, (int) $sesion->sucursal_id);
        $config = NegocioConfiguracion::query()->first();

        if (! $config instanceof NegocioConfiguracion || ! $config->permite_gastos_cajon_venta) {
            throw ValidationException::withMessages([
                'origen' => 'Los gastos desde el cajón de venta están desactivados.',
            ]);
        }

        if ($sesion->estado_sesion !== EstadoCajaSesion::Abierta) {
            throw ValidationException::withMessages([
                'origen' => 'Ese turno no está abierto.',
            ]);
        }

        if ((int) $sesion->user_id === (int) $actor->id) {
            $this->exigir($actor->can(Permisos::PROPIA), 'No podés registrar gastos en tu caja.');

            return;
        }

        $this->exigir($actor->can(Permisos::AJENA), 'No podés registrar gastos en la caja de otro.');
    }

    public function sesionParaEntrada(CajaSesion $sesion, TenantUser $actor): void
    {
        $this->sucursalAsignada($actor, (int) $sesion->sucursal_id);
        if ($sesion->estado_sesion !== EstadoCajaSesion::Abierta) {
            throw ValidationException::withMessages([
                'destino' => 'El sobrante tiene que entrar en un turno abierto.',
            ]);
        }

        if ((int) $sesion->user_id === (int) $actor->id) {
            $this->exigir($actor->can(Permisos::PROPIA) || $actor->can(Permisos::OPERAR) || $actor->can(Permisos::REVISAR), 'No podés recibir el sobrante en esa caja.');

            return;
        }

        $this->exigir($actor->can(Permisos::AJENA) || $actor->can(Permisos::REVISAR), 'No podés recibir el sobrante en esa caja.');
    }

    public function fondoParaOperar(CajaChicaFondo $fondo, TenantUser $actor): void
    {
        $this->sucursalAsignada($actor, (int) $fondo->sucursal_id);
        $this->exigir($actor->can(Permisos::OPERAR), 'No podés operar la caja chica.');
        $this->exigir(
            (int) $fondo->responsable_id === (int) $actor->id,
            'Ese fondo está asignado a otra persona.',
        );
    }

    public function sucursalAsignada(TenantUser $actor, int $sucursalId): void
    {
        $this->exigir($this->acceso->autoriza($actor, $sucursalId), 'No tenés esa sucursal asignada.');
    }

    /** @return Builder<CajaChicaMovimiento> */
    public function movimientosParaRendicion(TenantUser $actor): Builder
    {
        $sucursales = $actor->can(Permisos::AJENA) || $actor->can(Permisos::REVISAR)
            ? $this->acceso->ids($actor) : [];

        return $this->acceso->filtrarSucursal(CajaChicaMovimiento::query(), $actor)->where(function (Builder $query) use ($actor, $sucursales): void {
            $query->whereRaw('1 = 0');

            if ($actor->can(Permisos::PROPIA)) {
                $query->orWhereHas('sesion', fn (Builder $sesion): Builder => $sesion->where('user_id', $actor->id));
            }

            if ($actor->can(Permisos::OPERAR)) {
                $query->orWhereHas('fondo', fn (Builder $fondo): Builder => $fondo->where('responsable_id', $actor->id));
            }

            if ($sucursales !== []) {
                $query->orWhere(fn (Builder $ajena): Builder => $ajena
                    ->whereNotNull('caja_sesion_id')->whereIn('sucursal_id', $sucursales));

                if ($actor->can(Permisos::REVISAR)) {
                    $query->orWhereIn('sucursal_id', $sucursales);
                }
            }
        });
    }

    public function exigirAccesoARendicion(CajaChicaMovimiento $movimiento, TenantUser $actor, string $campo): void
    {
        if (! $this->movimientosParaRendicion($actor)->whereKey($movimiento->id)->exists()) {
            throw ValidationException::withMessages([$campo => 'No tenés acceso a ese movimiento de caja chica.']);
        }
    }

    public function compra(?int $ordenId): void
    {
        if ($ordenId === null) {
            return;
        }

        $orden = OrdenCompra::query()->whereKey($ordenId)->first();

        if (! $orden instanceof OrdenCompra) {
            throw ValidationException::withMessages([
                'orden_compra_id' => 'Esa compra no es de este negocio.',
            ]);
        }

        $ya = CajaChicaMovimiento::query()->where('orden_compra_id', $orden->id)->exists();

        if ($ya) {
            throw ValidationException::withMessages([
                'orden_compra_id' => 'Esa compra ya tiene un pago de caja chica.',
            ]);
        }
    }

    public function categoria(?int $categoriaId): void
    {
        if ($categoriaId === null) {
            return;
        }

        $categoria = CategoriaGasto::query()->whereKey($categoriaId)->where('is_active', true)->first();

        if (! $categoria instanceof CategoriaGasto) {
            throw ValidationException::withMessages([
                'categoria_gasto_id' => 'Esa categoría no está disponible.',
            ]);
        }
    }

    private function exigir(bool $condicion, string $mensaje): void
    {
        if (! $condicion) {
            throw ValidationException::withMessages(['origen' => $mensaje]);
        }
    }
}
