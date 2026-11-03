<?php

namespace App\Caja;

use App\Enums\EstadoCajaSesion;
use App\Models\CajaSesion;
use App\Models\TenantUser;

class CongelarTurnosVencidos
{
    /**
     * Un turno abierto de otro día deja de aceptar ventas y queda para contar.
     */
    public function de(TenantUser $usuario, ?int $sucursalId = null): int
    {
        $consulta = CajaSesion::query()
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->whereDate('fecha_apertura', '<', now()->toDateString());

        if ($sucursalId !== null) {
            $consulta->where('sucursal_id', $sucursalId);
        }

        return $consulta->update([
            'estado_sesion' => EstadoCajaSesion::PendienteArqueo,
        ]);
    }
}
