<?php

use Illuminate\Support\Facades\Route;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->get('/', fn () => to_route('filament.admin.pages.dashboard'));
}
