<?php

namespace App\Tenancy;

use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        if (! is_array($tables)) {
            return;
        }

        $connection = DB::connection($pool);

        foreach ($tables as $table) {
            if (! is_string($table) || ! Schema::connection($pool)->hasTable($table)) {
                continue;
            }

            $connection->table($table)->where('tenant_id', $tenantId)->delete();
        }
    }
}
