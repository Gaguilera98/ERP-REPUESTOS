<?php

namespace Database\Factories;

use App\Models\Venta;
use App\Models\VentaImpuesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VentaImpuesto>
 */
class VentaImpuestoFactory extends Factory
{
    protected $model = VentaImpuesto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'nombre_impuesto_snapshot' => 'IVA',
            'porcentaje_aplicado_snapshot' => '13.00',
            'monto_calculado' => '13.00',
        ];
    }
}
