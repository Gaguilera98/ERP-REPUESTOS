<?php

namespace App\Tenancy;

use Stancl\Tenancy\Events\TenancyEnded;

class RestoreCentralDatabaseConnection
{
    public function __construct(private TenantPoolManager $pools) {}

    public function handle(TenancyEnded $event): void
    {
        $this->pools->restore();
    }
}
