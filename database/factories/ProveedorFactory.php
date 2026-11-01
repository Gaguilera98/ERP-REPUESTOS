<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_empresa' => fake()->company(),
            'es_ocasional' => false,
        ];
    }
}
