<?php

namespace App\Policies;

use App\Models\InventarioStock;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class InventarioStockPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:InventarioStock');
    }

    public function view(TenantUser $user, InventarioStock $inventarioStock): bool
    {
        return $this->allows($user, 'View:InventarioStock');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:InventarioStock');
    }

    public function update(TenantUser $user, InventarioStock $inventarioStock): bool
    {
        return $this->allows($user, 'Update:InventarioStock');
    }

    public function delete(TenantUser $user, InventarioStock $inventarioStock): bool
    {
        return $this->allows($user, 'Delete:InventarioStock');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:InventarioStock');
    }
}
