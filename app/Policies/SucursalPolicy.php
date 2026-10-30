<?php

namespace App\Policies;

use App\Models\Sucursal;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class SucursalPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Sucursal');
    }

    public function view(TenantUser $user, Sucursal $sucursal): bool
    {
        return $this->allows($user, 'View:Sucursal');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Sucursal');
    }

    public function update(TenantUser $user, Sucursal $sucursal): bool
    {
        return $this->allows($user, 'Update:Sucursal');
    }

    public function delete(TenantUser $user, Sucursal $sucursal): bool
    {
        return $this->allows($user, 'Delete:Sucursal');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Sucursal');
    }
}
