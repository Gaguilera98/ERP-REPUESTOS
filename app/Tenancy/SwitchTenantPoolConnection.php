<?php

namespace App\Tenancy;

use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use Stancl\Tenancy\Events\TenancyInitialized;

class SwitchTenantPoolConnection
{
    public function __construct(private TenantPoolManager $pools) {}

    public function handle(TenancyInitialized $event): void
    {
        $pool = $event->tenancy->tenant->db_pool ?? null;

        try {
            if (! is_string($pool) || $pool === '') {
                throw new TenantPoolNotConfiguredException(is_string($pool) ? $pool : '');
            }

            $this->pools->switchTo($pool);
        } catch (TenantPoolNotConfiguredException $exception) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            throw $exception;
        }
    }
}
