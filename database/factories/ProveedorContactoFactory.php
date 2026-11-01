<?php

namespace Database\Factories;

use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProveedorContacto>
 */
class ProveedorContactoFactory extends Factory
{
    protected $model = ProveedorContacto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor_id' => Proveedor::factory(),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'es_principal' => false,
        ];
    }
}
