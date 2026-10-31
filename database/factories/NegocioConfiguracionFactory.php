<?php

namespace Database\Factories;

use App\Enums\ModoMoneda;
use App\Enums\Moneda;
use App\Models\NegocioConfiguracion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NegocioConfiguracion>
 */
class NegocioConfiguracionFactory extends Factory
{
    protected $model = NegocioConfiguracion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'horas_reserva' => 48,
            'descuento_max_porcentaje' => 10,
            'descuento_max_importe' => 0,
            'moneda' => Moneda::Bob,
            'modo_moneda' => ModoMoneda::SoloMoneda,
            'siguiente_numero_venta' => 1,
        ];
    }
}
