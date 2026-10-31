<?php

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\InventarioStock;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventarioStock>
 */
class InventarioStockFactory extends Factory
{
    protected $model = InventarioStock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producto_variante_id' => ProductoVariante::factory(),
            'almacen_id' => Almacen::factory(),
        ];
    }
}
