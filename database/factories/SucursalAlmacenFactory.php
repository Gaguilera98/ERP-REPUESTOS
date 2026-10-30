<?php

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SucursalAlmacen>
 */
class SucursalAlmacenFactory extends Factory
{
    protected $model = SucursalAlmacen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'almacen_id' => Almacen::factory(),
            'es_principal' => false,
        ];
    }
}
