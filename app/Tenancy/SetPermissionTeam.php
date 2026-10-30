<?php

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyInitialized;

class SetPermissionTeam
{
    public function handle(TenancyInitialized $event): void
    {
        setPermissionsTeamId((string) $event->tenancy->tenant->getTenantKey());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
