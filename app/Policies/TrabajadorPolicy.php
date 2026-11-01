<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\Trabajador;
use App\Policies\Concerns\ChecksTenantPermission;

class TrabajadorPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Trabajador');
    }

    public function view(TenantUser $user, Trabajador $trabajador): bool
    {
        return $this->allows($user, 'View:Trabajador');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Trabajador');
    }

    public function update(TenantUser $user, Trabajador $trabajador): bool
    {
        return $this->allows($user, 'Update:Trabajador');
    }

    public function delete(TenantUser $user, Trabajador $trabajador): bool
    {
        return ! $trabajador->ordenesRecibidas()->exists()
            && $this->allows($user, 'Delete:Trabajador');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Trabajador');
    }
}
