<?php

namespace App\Policies;

use App\Models\Categoria;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;

class CategoriaPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:Categoria');
    }

    public function view(TenantUser $user, Categoria $categoria): bool
    {
        return $this->allows($user, 'View:Categoria');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:Categoria');
    }

    public function update(TenantUser $user, Categoria $categoria): bool
    {
        return $this->allows($user, 'Update:Categoria');
    }

    public function delete(TenantUser $user, Categoria $categoria): bool
    {
        return $this->allows($user, 'Delete:Categoria');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:Categoria');
    }
}
