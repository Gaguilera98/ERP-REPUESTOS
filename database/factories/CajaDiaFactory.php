<?php

namespace Database\Factories;

use App\Models\CajaDia;
use App\Models\Sucursal;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaDia>
 */
class CajaDiaFactory extends Factory
{
    protected $model = CajaDia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'user_id' => TenantUser::factory(),
            'fecha' => now()->toDateString(),
            'fecha_cierre' => now(),
        ];
    }
}
