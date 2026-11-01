<?php

namespace Database\Factories;

use App\Models\ProductoVariante;
use App\Models\UnidadMedida;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VentaDetalle>
 */
class VentaDetalleFactory extends Factory
{
    protected $model = VentaDetalle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'producto_variante_id' => ProductoVariante::factory(),
            'unidad_medida_id' => UnidadMedida::factory(),
            'factor_a_base' => '1.000000',
            'cantidad' => '1.0000',
            'cantidad_base' => '1.0000',
            'precio_unitario' => '50.00',
            'precio_costo_snapshot' => '20.0000',
            'valor_descuento' => '0.00',
            'descuento_aplicado' => '0.00',
            'total_linea' => '50.00',
        ];
    }
}
