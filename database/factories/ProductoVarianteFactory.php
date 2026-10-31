<?php

namespace Database\Factories;

use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductoVariante>
 */
class ProductoVarianteFactory extends Factory
{
    protected $model = ProductoVariante::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producto_maestro_id' => ProductoMaestro::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('??####')),
            'precio_venta' => 10,
            'precio_costo' => 0,
            'is_active' => true,
        ];
    }
}
