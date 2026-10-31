<?php

namespace App\Policies;

use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class ProductoVariantePolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:ProductoVariante');
    }

    public function view(TenantUser $user, ProductoVariante $productoVariante): bool
    {
        return $this->allows($user, 'View:ProductoVariante');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:ProductoVariante');
    }

    public function update(TenantUser $user, ProductoVariante $productoVariante): bool
    {
        return $this->allows($user, 'Update:ProductoVariante');
    }

    public function delete(TenantUser $user, ProductoVariante $productoVariante): bool
    {
        return $this->allows($user, 'Delete:ProductoVariante');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:ProductoVariante');
    }
}
