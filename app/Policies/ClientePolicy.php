<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class ClientePolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Cliente');
    }

    public function view(TenantUser $user, Cliente $cliente): bool
    {
        return $this->allows($user, 'View:Cliente');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Cliente');
    }

    public function update(TenantUser $user, Cliente $cliente): bool
    {
        return $this->allows($user, 'Update:Cliente');
    }

    public function delete(TenantUser $user, Cliente $cliente): bool
    {
        return $this->allows($user, 'Delete:Cliente');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Cliente');
    }
}
