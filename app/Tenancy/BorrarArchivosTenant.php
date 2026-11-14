<?php

namespace App\Tenancy;

use App\Archivos\ArchivosNegocio;
use App\Models\Tenant;

class BorrarArchivosTenant
{
    public function __construct(protected Tenant $tenant) {}

    public function handle(): void
    {
        app(ArchivosNegocio::class)->borrarDelTenant((string) $this->tenant->getTenantKey());
    }
}
