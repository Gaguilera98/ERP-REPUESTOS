<?php

namespace App\Tenancy;

use App\Models\TenantUser;

class AccesoSucursales
{
    /**
     * @return list<int>
     */
    public function ids(TenantUser $usuario): array
    {
        return $usuario->sucursales()
            ->pluck('sucursales.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function autoriza(TenantUser $usuario, int $sucursalId): bool
    {
        return in_array($sucursalId, $this->ids($usuario), true);
    }
}
