<?php

namespace Database\Factories;

use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagoAplicacion>
 */
class PagoAplicacionFactory extends Factory
{
    protected $model = PagoAplicacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pago_id' => Pago::factory(),
            'venta_id' => Venta::factory(),
            'monto' => '100.00',
        ];
    }
}
