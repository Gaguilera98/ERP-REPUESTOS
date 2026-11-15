<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    PreventAccessFromCentralDomains::class,
    InitializeTenancyBySubdomain::class,
])->group(function () {
    Route::get('/', fn () => to_route('filament.negocio.pages.dashboard'));

    Route::get('/tenant/whoami', function () {
        return response()->json([
            'tenant_id' => tenant()->getTenantKey(),
            'connection' => DB::getDefaultConnection(),
        ]);
    });
});
