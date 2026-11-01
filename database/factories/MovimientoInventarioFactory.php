<?php

namespace Database\Factories;

use App\Enums\TipoMovimientoInventario;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoInventario>
 */
class MovimientoInventarioFactory extends Factory
{
    protected $model = MovimientoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producto_variante_id' => ProductoVariante::factory(),
            'almacen_id' => Almacen::factory(),
            'user_id' => TenantUser::factory(),
            'tipo_movimiento' => TipoMovimientoInventario::EntradaCompra,
            'cantidad' => 1,
            'stock_previo' => 0,
            'stock_posterior' => 1,
            'costo_unitario' => '10.0000',
            'costo_promedio_resultante' => '10.0000',
            'referencia_tipo' => MovimientoInventario::REFERENCIA_ORDEN_COMPRA,
            'referencia_id' => null,
        ];
    }
}
