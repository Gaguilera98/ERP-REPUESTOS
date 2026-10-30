<?php

namespace Database\Factories;

use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->company(),
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }
}
