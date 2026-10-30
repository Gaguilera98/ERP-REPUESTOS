<?php

namespace App\Policies;

use App\Models\Almacen;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class AlmacenPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Almacen');
    }

    public function view(TenantUser $user, Almacen $almacen): bool
    {
        return $this->allows($user, 'View:Almacen');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Almacen');
    }

    public function update(TenantUser $user, Almacen $almacen): bool
    {
        return $this->allows($user, 'Update:Almacen');
    }

    public function delete(TenantUser $user, Almacen $almacen): bool
    {
        return $this->allows($user, 'Delete:Almacen');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Almacen');
    }
}
