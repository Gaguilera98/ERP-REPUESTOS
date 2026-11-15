<?php

namespace App\Tenancy;

use App\Archivos\ArchivosNegocio;
use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Stancl\Tenancy\Contracts\Tenant;

class CleanTenantPoolData
{
    public function __construct(protected Tenant $tenant) {}

    public function handle(): void
    {
        $pool = $this->tenant->db_pool ?? null;

        if (! is_string($pool) || $pool === '') {
            throw new TenantPoolNotConfiguredException(is_string($pool) ? $pool : '');
        }

        app(TenantPoolManager::class)->assertConfigured($pool);

        $tenantId = (string) $this->tenant->getTenantKey();
        $tables = config('tenant-pools.tables', []);

        if (! is_array($tables) || $tables === []) {
            throw new LogicException('La lista de tablas del negocio está vacía o es inválida.');
        }

        $connection = DB::connection($pool);
        $schema = $connection->getSchemaBuilder();

        $connection->transaction(function () use ($connection, $schema, $tables, $tenantId): void {
            foreach ($tables as $table) {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                if ($table === 'role_has_permissions') {
                    $connection->table('role_has_permissions')
                        ->whereIn('role_id', function ($query) use ($tenantId): void {
                            $query->select('id')->from('roles')->where('tenant_id', $tenantId);
                        })
                        ->delete();

                    continue;
                }

                $connection->table($table)->where('tenant_id', $tenantId)->delete();
            }

            app(ArchivosNegocio::class)->borrarDelTenant($tenantId);
        });
    }
}
