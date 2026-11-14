<?php

namespace App\Filament\Negocio\Pages;

use App\Models\TenantUser;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        if (Filament::auth()->user() instanceof TenantUser && ConfiguracionInicial::estaPendiente()) {
            $this->redirect(ConfiguracionInicial::getUrl());
        }
    }
}
