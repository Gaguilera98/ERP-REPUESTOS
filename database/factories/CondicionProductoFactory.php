<?php

namespace Database\Factories;

use App\Models\CondicionProducto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CondicionProducto>
 */
class CondicionProductoFactory extends Factory
{
    protected $model = CondicionProducto::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->slug(2),
            'nombre' => fake()->unique()->words(2, true),
            'is_active' => true,
            'orden' => 0,
        ];
    }
}
