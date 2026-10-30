<?php

namespace App\Tenancy;

use App\Models\NegocioRole;
use App\Models\User;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SeedPlatformRoles
{
    public function seed(): void
    {
        if (tenancy()->initialized) {
            throw new RuntimeException('Los roles de la plataforma se siembran sin un negocio abierto.');
        }

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId(PlatformTeam::Id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            foreach ($this->permissionNames() as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $admin = NegocioRole::findOrCreate('admin', 'web');
            $admin->syncPermissions($this->permissionNames());

            User::query()
                ->whereDoesntHave('roles')
                ->each(fn (User $user) => $user->assignRole('admin'));
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $names = [];
        $methods = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        ];

        foreach (['Tenant', 'User'] as $subject) {
            foreach ($methods as $method) {
                $names[] = $method.':'.$subject;
            }
        }

        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $method) {
            $names[] = $method.':NegocioRole';
        }

        return $names;
    }
}
