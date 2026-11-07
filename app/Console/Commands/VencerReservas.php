<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Ventas\GestionarReservas;
use Illuminate\Console\Command;

class VencerReservas extends Command
{
    protected $signature = 'ventas:vencer-reservas';

    protected $description = 'Libera las reservas vencidas de cada negocio';

    public function handle(GestionarReservas $reservas): int
    {
        $anterior = tenant();
        try {
            foreach (Tenant::query()->cursor() as $tenant) {
                tenancy()->initialize($tenant);
                $reservas->procesarVencidas();
                tenancy()->end();
            }
        } finally {
            if ($anterior) {
                tenancy()->initialize($anterior);
            } elseif (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }
}
