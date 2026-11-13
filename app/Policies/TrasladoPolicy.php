<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\Traslado;
use App\Policies\Concerns\ChecksTenantPermission;

class TrasladoPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'Trasladar:Inventario');
    }

    public function view(TenantUser $user, Traslado $traslado): bool
    {
        return $this->allows($user, 'Trasladar:Inventario');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Trasladar:Inventario');
    }

    public function update(TenantUser $user, Traslado $traslado): bool
    {
        return false;
    }

    public function delete(TenantUser $user, Traslado $traslado): bool
    {
        return false;
    }
}
