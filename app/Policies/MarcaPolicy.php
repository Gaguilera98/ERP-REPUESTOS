<?php

namespace App\Policies;

use App\Models\Marca;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class MarcaPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Marca');
    }

    public function view(TenantUser $user, Marca $marca): bool
    {
        return $this->allows($user, 'View:Marca');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Marca');
    }

    public function update(TenantUser $user, Marca $marca): bool
    {
        return $this->allows($user, 'Update:Marca');
    }

    public function delete(TenantUser $user, Marca $marca): bool
    {
        return $this->allows($user, 'Delete:Marca');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Marca');
    }
}
