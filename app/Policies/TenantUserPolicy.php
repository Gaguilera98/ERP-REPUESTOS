<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class TenantUserPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:TenantUser');
    }

    public function view(TenantUser $user, TenantUser $model): bool
    {
        return $this->allows($user, 'View:TenantUser');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:TenantUser');
    }

    public function update(TenantUser $user, TenantUser $model): bool
    {
        return $this->allows($user, 'Update:TenantUser');
    }

    public function delete(TenantUser $user, TenantUser $model): bool
    {
        return $this->allows($user, 'Delete:TenantUser');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:TenantUser');
    }
}
