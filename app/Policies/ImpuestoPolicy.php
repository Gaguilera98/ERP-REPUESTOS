<?php

namespace App\Policies;

use App\Models\Impuesto;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class ImpuestoPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Impuesto');
    }

    public function view(TenantUser $user, Impuesto $impuesto): bool
    {
        return $this->allows($user, 'View:Impuesto');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Impuesto');
    }

    public function update(TenantUser $user, Impuesto $impuesto): bool
    {
        return $this->allows($user, 'Update:Impuesto');
    }

    public function delete(TenantUser $user, Impuesto $impuesto): bool
    {
        return $this->allows($user, 'Delete:Impuesto');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Impuesto');
    }
}
