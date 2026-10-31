<?php

namespace Database\Factories;

use App\MagnitudUnidad;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->word();

        return [
            'nombre' => ucfirst($nombre),
            'simbolo' => fake()->unique()->lexify('??'),
            'magnitud' => MagnitudUnidad::Longitud,
        ];
    }
}
