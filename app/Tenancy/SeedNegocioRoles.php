<?php

namespace App\Tenancy;

use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use App\Models\NegocioConfiguracion;
use App\Models\NegocioRole;
use App\Models\Tenant;
use App\ModoMoneda;
use App\Moneda;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SeedNegocioRoles
{
    public function __construct(protected Tenant $tenant) {}

    public function handle(): void
    {
        $pool = $this->tenant->db_pool ?? null;

        if (! is_string($pool) || $pool === '') {
            return;
        }

        try {
            app(TenantPoolManager::class)->assertConfigured($pool);
        } catch (TenantPoolNotConfiguredException) {
            return;
        }

        $previousTeamId = getPermissionsTeamId();

        tenancy()->initialize($this->tenant);

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->seed();
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if (tenancy()->initialized) {
                tenancy()->end();
            }

            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seed(): void
    {
        setPermissionsTeamId((string) $this->tenant->getTenantKey());

        foreach ($this->permissionNames() as $name) {
            Permission::findOrCreate($name, 'tenant');
        }

        $supervisor = NegocioRole::findOrCreate('supervisor', 'tenant');
        $supervisor->syncPermissions($this->permissionNames());

        $cajero = NegocioRole::findOrCreate('cajero', 'tenant');
        $cajero->syncPermissions([
            'ViewAny:Sucursal',
            'View:Sucursal',
            'ViewAny:Almacen',
            'View:Almacen',
        ]);

        NegocioConfiguracion::query()->firstOrCreate([], [
            'horas_reserva' => 48,
            'descuento_max_porcentaje' => 10,
            'descuento_max_importe' => 0,
            'moneda' => Moneda::Bob,
            'modo_moneda' => ModoMoneda::SoloMoneda,
            'siguiente_numero_venta' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $names = ['View:ConfiguracionDelNegocio'];
        $methods = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
            'Restore', 'ForceDelete', 'ForceDeleteAny', 'RestoreAny', 'Replicate', 'Reorder',
        ];

        foreach (['Sucursal', 'Almacen', 'TenantUser'] as $subject) {
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
