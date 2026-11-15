<?php

namespace App\Caja;

use App\Enums\EstadoCajaSesion;
use App\Models\CajaDia;
use App\Models\CajaSesion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CerrarCajaDia
{
    public function cerrar(int $sucursalId, string $fecha, TenantUser $usuario): CajaDia
    {
        $acceso = app(AccesoSucursales::class);
        abort_unless($usuario->can('Cerrar:CajaDia') && $acceso->autoriza($usuario, $sucursalId), 403);
        $dia = Carbon::parse($fecha)->toDateString();

        return DB::transaction(function () use ($sucursalId, $dia, $usuario): CajaDia {
            if (! Sucursal::query()->whereKey($sucursalId)->exists()) {
                throw ValidationException::withMessages([
                    'sucursal_id' => 'Esa sucursal no es de este negocio.',
                ]);
            }

            $yaCerrado = CajaDia::query()
                ->where('sucursal_id', $sucursalId)
                ->whereDate('fecha', $dia)
                ->lockForUpdate()
                ->exists();

            if ($yaCerrado) {
                throw ValidationException::withMessages([
                    'fecha' => 'Ese día ya está cerrado.',
                ]);
            }

            $sinContar = $this->sesiones($sucursalId, $dia)
                ->whereIn('estado_sesion', [
                    EstadoCajaSesion::Abierta,
                    EstadoCajaSesion::PendienteArqueo,
                ])
                ->lockForUpdate()
                ->exists();

            if ($sinContar) {
                throw ValidationException::withMessages([
                    'fecha' => 'Todavía hay sesiones sin contar.',
                ]);
            }

            $cerradas = $this->sesiones($sucursalId, $dia)
                ->where('estado_sesion', EstadoCajaSesion::Cerrada)
                ->lockForUpdate()
                ->get();

            if ($cerradas->isEmpty()) {
                throw ValidationException::withMessages([
                    'fecha' => 'No hay sesiones cerradas para firmar el día.',
                ]);
            }

            $cierre = CajaDia::query()->create([
                'sucursal_id' => $sucursalId,
                'fecha' => $dia,
                'user_id' => $usuario->id,
                'fecha_cierre' => now(),
            ]);

            CajaSesion::query()
                ->whereIn('id', $cerradas->modelKeys())
                ->update(['caja_dia_id' => $cierre->id]);

            return $cierre;
        });
    }

    private function sesiones(int $sucursalId, string $fecha): Builder
    {
        return CajaSesion::query()
            ->where('sucursal_id', $sucursalId)
            ->whereDate('fecha_apertura', $fecha);
    }
}
