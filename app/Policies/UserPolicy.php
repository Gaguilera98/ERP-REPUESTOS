<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class UserPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ViewAny:User');
    }

    public function view(User $user, User $model): bool
    {
        return $this->allows($user, 'View:User');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'Create:User');
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, 'Update:User');
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $this->allows($user, 'Delete:User');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'DeleteAny:User');
    }
}
