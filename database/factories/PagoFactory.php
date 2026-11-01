<?php

namespace Database\Factories;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Models\Pago;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pago>
 */
class PagoFactory extends Factory
{
    protected $model = Pago::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sentido' => SentidoPago::Cobro,
            'metodo_pago' => MetodoPago::Efectivo,
            'monto' => '100.00',
            'estado_pago' => EstadoPago::Aprobado,
            'fecha_pago' => now(),
        ];
    }
}
