<?php

namespace Database\Factories;

use App\Enums\EstadoCajaSesion;
use App\Models\CajaSesion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaSesion>
 */
class CajaSesionFactory extends Factory
{
    protected $model = CajaSesion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'user_id' => TenantUser::factory(),
            'estado_sesion' => EstadoCajaSesion::Abierta,
            'fecha_apertura' => now(),
            'monto_apertura' => '100.00',
            'diferencia' => '0.00',
        ];
    }
}
