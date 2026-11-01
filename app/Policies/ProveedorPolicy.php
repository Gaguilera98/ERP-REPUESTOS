<?php

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class ProveedorPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Proveedor');
    }

    public function view(TenantUser $user, Proveedor $proveedor): bool
    {
        return $this->allows($user, 'View:Proveedor');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Proveedor');
    }

    public function update(TenantUser $user, Proveedor $proveedor): bool
    {
        return $this->allows($user, 'Update:Proveedor');
    }

    public function delete(TenantUser $user, Proveedor $proveedor): bool
    {
        return ! $proveedor->es_ocasional
            && ! $proveedor->ordenes()->exists()
            && $this->allows($user, 'Delete:Proveedor');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Proveedor');
    }
}
