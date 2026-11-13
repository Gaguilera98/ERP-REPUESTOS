<?php

namespace App\CajaChica;

use App\Compras\Montos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\TipoCajaChicaArqueo;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\CajaChicaArqueo;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FondosCajaChica
{
    /**
     * @param  array{sucursal_id: int, responsable_id: int, monto_apertura: string}  $data
     */
    public function abrir(array $data, TenantUser $actor): CajaChicaFondo
    {
        if (! $actor->can(Permisos::FONDOS)) {
            throw ValidationException::withMessages([
                'fondo' => 'No podés abrir un fondo de caja chica.',
            ]);
        }

        $sucursalId = (int) $data['sucursal_id'];

        if (! app(AccesoSucursales::class)->autoriza($actor, $sucursalId)) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'No tenés esa sucursal asignada.',
            ]);
        }

        if (CajaChicaFondo::query()->where('sucursal_id', $sucursalId)->exists()) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'Esa sucursal ya tiene un fondo de caja chica.',
            ]);
        }

        $responsable = TenantUser::query()->whereKey((int) $data['responsable_id'])->first();

        if (! $responsable instanceof TenantUser) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Ese responsable no es de este negocio.',
            ]);
        }

        $monto = Montos::dos((string) $data['monto_apertura']);

        return DB::transaction(function () use ($sucursalId, $responsable, $monto, $actor): CajaChicaFondo {
            $fondo = CajaChicaFondo::query()->create([
                'sucursal_id' => $sucursalId,
                'responsable_id' => $responsable->id,
                'monto_apertura' => $monto,
                'saldo_actual' => $monto,
                'estado' => 'activo',
            ]);

            CajaChicaArqueo::query()->create([
                'fondo_id' => $fondo->id,
                'tipo' => TipoCajaChicaArqueo::Apertura,
                'teorico' => $monto,
                'contado' => $monto,
                'diferencia' => '0.00',
                'user_id' => $actor->id,
                'recibe_user_id' => $responsable->id,
            ]);

            return $fondo;
        });
    }

    /**
     * @param  array{responsable_id: int, contado: string, justificacion?: string|null}  $data
     */
    public function cambiarResponsable(CajaChicaFondo $fondo, array $data, TenantUser $actor): CajaChicaFondo
    {
        if (! $actor->can(Permisos::FONDOS)) {
            throw ValidationException::withMessages([
                'fondo' => 'No podés cambiar el responsable del fondo.',
            ]);
        }

        if (! app(AccesoSucursales::class)->autoriza($actor, (int) $fondo->sucursal_id)) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'No tenés esa sucursal asignada.',
            ]);
        }

        $nuevo = TenantUser::query()->whereKey((int) $data['responsable_id'])->first();

        if (! $nuevo instanceof TenantUser) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Ese responsable no es de este negocio.',
            ]);
        }

        return DB::transaction(function () use ($fondo, $data, $actor, $nuevo): CajaChicaFondo {
            $fondo = CajaChicaFondo::query()->whereKey($fondo->id)->lockForUpdate()->firstOrFail();
            $teorico = Montos::dos((string) $fondo->saldo_actual);
            $contado = Montos::dos((string) $data['contado']);
            $diferencia = Montos::dos(bcsub($contado, $teorico, 4));
            $justificacion = is_string($data['justificacion'] ?? null) ? trim($data['justificacion']) : '';

            if (Montos::comparar($diferencia, '0', 2) !== 0 && $justificacion === '') {
                throw ValidationException::withMessages([
                    'justificacion' => 'Si el contado no calza con el saldo, tenés que justificarlo.',
                ]);
            }

            $anterior = (int) $fondo->responsable_id;
            $fondo->fill([
                'responsable_id' => $nuevo->id,
                'saldo_actual' => $contado,
            ]);
            $fondo->save();

            CajaChicaMovimiento::query()
                ->where('fondo_id', $fondo->id)
                ->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
                ->where('estado', EstadoCajaChicaMovimiento::PendienteRendir)
                ->update(['seguimiento_user_id' => $nuevo->id]);

            CajaChicaArqueo::query()->create([
                'fondo_id' => $fondo->id,
                'tipo' => TipoCajaChicaArqueo::Entrega,
                'teorico' => $teorico,
                'contado' => $contado,
                'diferencia' => $diferencia,
                'justificacion' => $justificacion !== '' ? $justificacion : null,
                'user_id' => $actor->id,
                'entrega_user_id' => $anterior,
                'recibe_user_id' => $nuevo->id,
            ]);

            return $fondo->refresh();
        });
    }
}
