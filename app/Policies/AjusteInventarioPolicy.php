<?php

namespace App\Policies;

use App\Models\AjusteInventario;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class AjusteInventarioPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'Cargar:Conteo')
            || $this->allows($user, 'Cerrar:Conteo')
            || $this->allows($user, 'Ajustar:Inventario');
    }

    public function view(TenantUser $user, AjusteInventario $ajuste): bool
    {
        return $this->viewAny($user);
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Cargar:Conteo');
    }

    public function update(TenantUser $user, AjusteInventario $ajuste): bool
    {
        return false;
    }

    public function delete(TenantUser $user, AjusteInventario $ajuste): bool
    {
        return false;
    }
}
