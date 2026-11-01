<?php

namespace Database\Factories;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenCompraDetalle>
 */
class OrdenCompraDetalleFactory extends Factory
{
    protected $model = OrdenCompraDetalle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'orden_compra_id' => OrdenCompra::factory(),
            'producto_variante_id' => ProductoVariante::factory(),
            'cantidad_pedida' => 1,
            'cantidad_recibida' => 0,
            'costo_unitario' => '10.0000',
            'costo_unitario_origen' => '10.0000',
            'subtotal_linea' => '10.00',
        ];
    }
}
