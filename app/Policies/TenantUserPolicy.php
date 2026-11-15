<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\AccesoSucursales;

class TenantUserPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:TenantUser');
    }

    public function view(TenantUser $user, TenantUser $model): bool
    {
        return $this->allows($user, 'View:TenantUser');
    }

    public function create(TenantUser $user): bool
    {
        return $this->administraAcceso($user) && $this->allows($user, 'Create:TenantUser');
    }

    public function update(TenantUser $user, TenantUser $model): bool
    {
        return $this->administraAcceso($user)
            && (! $model->hasRole(AccesoSucursales::ADMINISTRADOR) || $user->hasRole(AccesoSucursales::ADMINISTRADOR))
            && $this->allows($user, 'Update:TenantUser');
    }

    public function delete(TenantUser $user, TenantUser $model): bool
    {
        return $this->administraAcceso($user)
            && ! $model->hasRole(AccesoSucursales::ADMINISTRADOR)
            && $this->allows($user, 'Delete:TenantUser');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->administraAcceso($user) && $this->allows($user, 'DeleteAny:TenantUser');
    }

    private function administraAcceso(TenantUser $user): bool
    {
        return $user->can('Administrar:AccesoUsuarios');
    }
}
