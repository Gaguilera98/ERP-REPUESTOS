<?php

namespace App\Filament\Negocio\Resources\TenantUsers;

use App\Models\Sucursal;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Log;

class SincronizaSucursales
{
    /**
     * @param  list<int|string>  $sucursales
     */
    public static function aplicar(TenantUser $usuario, array $sucursales): void
    {
        $tenantId = (string) tenant()->getTenantKey();
        $ids = [];

        foreach (Sucursal::query()->whereKey($sucursales)->pluck('id') as $id) {
            $ids[(int) $id] = ['tenant_id' => $tenantId];
        }

        $cambios = $usuario->sucursales()->sync($ids);
        if ($cambios['attached'] !== [] || $cambios['detached'] !== []) {
            Log::info('Sucursales asignadas actualizadas', [
                'tenant_id' => $tenantId, 'actor_id' => auth('tenant')->id(), 'usuario_id' => $usuario->id,
                'agregadas' => $cambios['attached'], 'retiradas' => $cambios['detached'],
            ]);
        }
    }
}
