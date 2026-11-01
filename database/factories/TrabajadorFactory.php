<?php

namespace Database\Factories;

use App\Models\Trabajador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trabajador>
 */
class TrabajadorFactory extends Factory
{
    protected $model = Trabajador::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'is_active' => true,
        ];
    }
}
