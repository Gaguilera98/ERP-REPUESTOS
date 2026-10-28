<?php

namespace App\Tenancy;

use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantPoolManager
{
    private ?string $previousDefault = null;

    public function switchTo(string $pool): void
    {
        $this->assertConfigured($pool);

        if ($this->previousDefault === null) {
            $this->previousDefault = (string) config('database.default');
        }

        Config::set('database.default', $pool);
        DB::setDefaultConnection($pool);
    }

    public function restore(): void
    {
        if ($this->previousDefault === null) {
            return;
        }

        $previous = $this->previousDefault;
        $this->previousDefault = null;

        Config::set('database.default', $previous);
        DB::setDefaultConnection($previous);
    }

    public function assertConfigured(string $pool): void
    {
        $pools = config('tenant-pools.pools');

        if (! is_array($pools) || ! array_key_exists($pool, $pools)) {
            throw new TenantPoolNotConfiguredException($pool);
        }

        if (! is_array(config("database.connections.{$pool}"))) {
            throw new TenantPoolNotConfiguredException($pool);
        }
    }
}
