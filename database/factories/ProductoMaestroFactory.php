<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\ProductoMaestro;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductoMaestro>
 */
class ProductoMaestroFactory extends Factory
{
    protected $model = ProductoMaestro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(3, true),
            'categoria_id' => Categoria::factory(),
            'is_active' => true,
        ];
    }
}
