<?php

namespace App\Policies;

use App\Models\CajaSesion;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class CajaSesionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:CajaSesion');
    }

    public function view(TenantUser $user, CajaSesion $cajaSesion): bool
    {
        if (! $this->allows($user, 'View:CajaSesion')) {
            return false;
        }

        if ((int) $cajaSesion->user_id === (int) $user->id) {
            return true;
        }

        return $user->hasRole('supervisor');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:CajaSesion');
    }

    public function update(TenantUser $user, CajaSesion $cajaSesion): bool
    {
        return $this->allows($user, 'Update:CajaSesion')
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
