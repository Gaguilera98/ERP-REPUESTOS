<?php

namespace App\Policies;

use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class NegocioConfiguracionPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'View:ConfiguracionDelNegocio');
    }

    public function view(TenantUser $user, NegocioConfiguracion $negocioConfiguracion): bool
    {
        return $this->allows($user, 'View:ConfiguracionDelNegocio');
    }

    public function update(TenantUser $user, NegocioConfiguracion $negocioConfiguracion): bool
    {
        return $this->allows($user, 'View:ConfiguracionDelNegocio');
    }
}
