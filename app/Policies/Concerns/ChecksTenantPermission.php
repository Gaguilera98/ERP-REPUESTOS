<?php

namespace App\Policies\Concerns;

use Illuminate\Contracts\Auth\Access\Authorizable;

trait ChecksTenantPermission
{
    protected function allows(Authorizable $user, string $permission): bool
    {
        return $user->can($permission);
    }
}
