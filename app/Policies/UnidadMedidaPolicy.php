<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\UnidadMedida;
use App\Policies\Concerns\ChecksTenantPermission;

class UnidadMedidaPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:UnidadMedida');
    }

    public function view(TenantUser $user, UnidadMedida $unidadMedida): bool
    {
        return $this->allows($user, 'View:UnidadMedida');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:UnidadMedida');
    }

    public function update(TenantUser $user, UnidadMedida $unidadMedida): bool
    {
        return $this->allows($user, 'Update:UnidadMedida');
    }

    public function delete(TenantUser $user, UnidadMedida $unidadMedida): bool
    {
        return $this->allows($user, 'Delete:UnidadMedida');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:UnidadMedida');
    }
}
