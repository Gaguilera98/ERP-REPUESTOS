<?php

namespace App\Policies;

use App\Models\CajaSesion;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\AccesoSucursales;

class CajaSesionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:CajaSesion');
    }

    public function view(TenantUser $user, CajaSesion $cajaSesion): bool
    {
        $acceso = app(AccesoSucursales::class);
        if (! $this->allows($user, 'View:CajaSesion')
            || ! $acceso->autoriza($user, (int) $cajaSesion->sucursal_id)) {
            return false;
        }

        if ((int) $cajaSesion->user_id === (int) $user->id) {
            return true;
        }

        return $user->can('View:CajasDelDia');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:CajaSesion');
    }

    public function update(TenantUser $user, CajaSesion $cajaSesion): bool
    {
        return $this->view($user, $cajaSesion) && $this->allows($user, 'Update:CajaSesion')
            && (int) $cajaSesion->user_id === (int) $user->id
            && $cajaSesion->aceptaConteo();
    }

    public function delete(TenantUser $user, CajaSesion $cajaSesion): bool
    {
        return false;
    }

    public function deleteAny(TenantUser $user): bool
    {
        return false;
    }
}
