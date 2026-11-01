<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\Venta;
use App\Policies\Concerns\ChecksTenantPermission;

class VentaPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Venta');
    }

    public function view(TenantUser $user, Venta $venta): bool
    {
        return $this->allows($user, 'View:Venta');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Venta');
    }

    public function update(TenantUser $user, Venta $venta): bool
    {
        return false;
    }

    public function delete(TenantUser $user, Venta $venta): bool
    {
        return false;
    }

    public function deleteAny(TenantUser $user): bool
    {
        return false;
    }
}
