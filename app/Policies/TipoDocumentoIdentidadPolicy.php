<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\TipoDocumentoIdentidad;
use App\Policies\Concerns\ChecksTenantPermission;

class TipoDocumentoIdentidadPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:TipoDocumentoIdentidad');
    }

    public function view(TenantUser $user, TipoDocumentoIdentidad $tipoDocumentoIdentidad): bool
    {
        return $this->allows($user, 'View:TipoDocumentoIdentidad');
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:TipoDocumentoIdentidad');
    }

    public function update(TenantUser $user, TipoDocumentoIdentidad $tipoDocumentoIdentidad): bool
    {
        return $this->allows($user, 'Update:TipoDocumentoIdentidad');
    }

    public function delete(TenantUser $user, TipoDocumentoIdentidad $tipoDocumentoIdentidad): bool
    {
        return $this->allows($user, 'Delete:TipoDocumentoIdentidad');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:TipoDocumentoIdentidad');
    }
}
