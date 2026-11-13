<?php

namespace App\Policies;

use App\Models\CorreccionCosto;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class CorreccionCostoPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'Corregir:Costo');
    }

    public function view(TenantUser $user, CorreccionCosto $correccion): bool
    {
        return $this->allows($user, 'Corregir:Costo');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Corregir:Costo');
    }
}
