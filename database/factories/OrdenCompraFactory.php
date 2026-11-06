<?php

namespace Database\Factories;

use App\Enums\EstadoOrdenCompra;
use App\Models\Almacen;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenCompra>
 */
class OrdenCompraFactory extends Factory
{
    protected $model = OrdenCompra::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor_id' => Proveedor::factory(),
            'user_id' => TenantUser::factory(),
            'almacen_destino_id' => Almacen::factory(),
            'es_compra_flash' => false,
            'estado' => EstadoOrdenCompra::Pendiente,
            'subtotal' => 0,
            'total' => 0,
            'fecha_emision' => now(),
        ];
    }
}
