<?php

namespace App\Actions;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Rules\NegocioDomainRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class CreateNegocio
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tenant
    {
        $domain = NegocioDomainRule::normalize(is_string($data['domain'] ?? null) ? $data['domain'] : '');

        Validator::make(
            [
                'name' => $data['name'] ?? null,
                'db_pool' => $data['db_pool'] ?? null,
                'domain' => $domain,
                'is_active' => $data['is_active'] ?? true,
                'supervisor_name' => $data['supervisor_name'] ?? null,
                'supervisor_email' => $data['supervisor_email'] ?? null,
                'supervisor_password' => $data['supervisor_password'] ?? null,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'db_pool' => ['required', 'string', Rule::in(array_keys(config('tenant-pools.pools')))],
                'domain' => ['required', 'string', new NegocioDomainRule],
                'is_active' => ['boolean'],
                'supervisor_name' => ['required', 'string', 'max:255'],
                'supervisor_email' => ['required', 'email', 'max:255'],
                'supervisor_password' => ['required', 'string', 'min:8', 'max:255'],
            ],
            [
                'db_pool.in' => 'Esa base no está registrada.',
                'supervisor_password.min' => 'La contraseña del supervisor debe tener al menos 8 caracteres.',
            ],
        )->validate();

        $tenant = Tenant::query()->create([
            'name' => $data['name'],
            'db_pool' => $data['db_pool'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $tenant->domains()->create([
            'domain' => $domain,
        ]);

        $this->createSupervisor($tenant, $data);

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSupervisor(Tenant $tenant, array $data): void
    {
        $previousTeamId = getPermissionsTeamId();

        tenancy()->initialize($tenant);

        try {
            $supervisor = TenantUser::query()->create([
                'name' => $data['supervisor_name'],
                'email' => $data['supervisor_email'],
                'password' => $data['supervisor_password'],
                'is_active' => true,
            ]);

            $supervisor->assignRole('supervisor');
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
