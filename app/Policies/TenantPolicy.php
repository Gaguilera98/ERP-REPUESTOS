<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class TenantPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ViewAny:Tenant');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, 'View:Tenant');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'Create:Tenant');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, 'Update:Tenant');
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, 'Delete:Tenant');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'DeleteAny:Tenant');
    }
}
