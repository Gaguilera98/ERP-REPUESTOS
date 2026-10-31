<?php

namespace App\Policies;

use App\Models\ProductoMaestro;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class ProductoMaestroPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:ProductoMaestro');
    }

    public function view(TenantUser $user, ProductoMaestro $productoMaestro): bool
    {
        return $this->allows($user, 'View:ProductoMaestro');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:ProductoMaestro');
    }

    public function update(TenantUser $user, ProductoMaestro $productoMaestro): bool
    {
        return $this->allows($user, 'Update:ProductoMaestro');
    }

    public function delete(TenantUser $user, ProductoMaestro $productoMaestro): bool
    {
        return $this->allows($user, 'Delete:ProductoMaestro');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:ProductoMaestro');
    }
}
