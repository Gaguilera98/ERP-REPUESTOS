<?php

namespace App\Policies;

use App\CajaChica\Permisos;
use App\Models\CategoriaGasto;
use App\Models\TenantUser;

class CategoriaGastoPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->can(Permisos::CATEGORIAS);
    }

    public function view(TenantUser $user, CategoriaGasto $categoriaGasto): bool
    {
        return $user->can(Permisos::CATEGORIAS);
    }

    public function create(TenantUser $user): bool
    {
        return $user->can(Permisos::CATEGORIAS);
    }

    public function update(TenantUser $user, CategoriaGasto $categoriaGasto): bool
    {
        return $user->can(Permisos::CATEGORIAS);
    }

    public function delete(TenantUser $user, CategoriaGasto $categoriaGasto): bool
    {
        return false;
    }

    public function deleteAny(TenantUser $user): bool
    {
        return false;
    }
}
