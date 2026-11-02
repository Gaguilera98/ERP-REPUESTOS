<?php

namespace Database\Factories;

use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\CajaSesionMedio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaSesionMedio>
 */
class CajaSesionMedioFactory extends Factory
{
    protected $model = CajaSesionMedio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'caja_sesion_id' => CajaSesion::factory(),
            'metodo_pago' => MetodoPago::Efectivo,
            'monto_esperado' => '100.00',
            'monto_declarado' => '100.00',
            'diferencia' => '0.00',
        ];
    }
}
