<?php

namespace App\Policies;

use App\Models\NegocioRole;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\AccesoSucursales;
use Illuminate\Contracts\Auth\Access\Authorizable;

class RolePolicy
{
    use ChecksTenantPermission;

    public function viewAny(Authorizable $user): bool
    {
        return $this->allows($user, 'ViewAny:NegocioRole');
    }

    public function view(Authorizable $user, NegocioRole $role): bool
    {
        return $this->allows($user, 'View:NegocioRole');
    }

    public function create(Authorizable $user): bool
    {
        return $this->administraRoles($user) && $this->allows($user, 'Create:NegocioRole');
    }

    public function update(Authorizable $user, NegocioRole $role): bool
    {
        return $this->administraRoles($user) && $role->name !== AccesoSucursales::ADMINISTRADOR && $this->allows($user, 'Update:NegocioRole');
    }

    public function delete(Authorizable $user, NegocioRole $role): bool
    {
        return $this->administraRoles($user) && $role->name !== AccesoSucursales::ADMINISTRADOR && $this->allows($user, 'Delete:NegocioRole');
    }

    private function administraRoles(Authorizable $user): bool
    {
        return ! $user instanceof TenantUser || $user->hasRole(AccesoSucursales::ADMINISTRADOR);
    }
}
