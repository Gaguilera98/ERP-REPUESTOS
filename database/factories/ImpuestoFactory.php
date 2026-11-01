<?php

namespace Database\Factories;

use App\Models\Impuesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Impuesto>
 */
class ImpuestoFactory extends Factory
{
    protected $model = Impuesto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'IVA '.fake()->unique()->numberBetween(1, 99),
            'porcentaje' => '13.00',
            'is_active' => true,
        ];
    }
}
