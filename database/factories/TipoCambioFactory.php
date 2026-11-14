<?php

namespace Database\Factories;

use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Models\TipoCambio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TipoCambio> */
class TipoCambioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'moneda_local' => NegocioConfiguracion::codigoMoneda(),
            'valor' => '10.0000',
            'user_id' => TenantUser::factory(),
        ];
    }
}
