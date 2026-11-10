<?php

namespace Database\Factories;

use App\Enums\CondicionVenta;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
{
    protected $model = Venta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => 'VNT-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'sucursal_id' => Sucursal::factory(),
            'almacen_id' => Almacen::factory(),
            'user_id' => TenantUser::factory(),
            'condicion' => CondicionVenta::Contado,
            'estado' => EstadoVenta::Completada,
            'politica_devolucion_snapshot' => fn (): array => NegocioConfiguracion::query()->firstOrFail()->politicaDevolucion(),
            'entregada_en' => now(),
            'tipo_comprobante' => TipoComprobante::NotaVenta,
            'subtotal' => '100.00',
            'descuento' => '0.00',
            'total' => '100.00',
        ];
    }
}
