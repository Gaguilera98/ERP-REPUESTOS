<?php

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;

class ClearPermissionTeam
{
    public function handle(): void
    {
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
