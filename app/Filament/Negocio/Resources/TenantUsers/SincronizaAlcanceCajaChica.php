<?php

namespace App\Filament\Negocio\Resources\TenantUsers;

use App\CajaChica\Permisos;
use App\Models\TenantUser;

class SincronizaAlcanceCajaChica
{
    /**
     * @param  list<int|string>  $sucursales
     * @param  list<string>  $permisos
     */
    public static function aplicar(TenantUser $usuario, array $sucursales, array $permisos): void
    {
        $tenantId = (string) tenant()->getTenantKey();
        $ids = [];

        foreach ($sucursales as $id) {
            $ids[(int) $id] = ['tenant_id' => $tenantId];
        }

        $usuario->sucursales()->sync($ids);

        foreach (Permisos::nombres() as $nombre) {
            if (in_array($nombre, $permisos, true)) {
                $usuario->givePermissionTo($nombre);
            } elseif ($usuario->hasDirectPermission($nombre)) {
                $usuario->revokePermissionTo($nombre);
            }
        }
    }
}
