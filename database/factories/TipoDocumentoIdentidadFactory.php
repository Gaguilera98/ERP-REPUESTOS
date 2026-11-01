<?php

namespace Database\Factories;

use App\Models\TipoDocumentoIdentidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoDocumentoIdentidad>
 */
class TipoDocumentoIdentidadFactory extends Factory
{
    protected $model = TipoDocumentoIdentidad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->lexify('doc_????'),
            'nombre' => fake()->words(2, true),
            'para_persona_natural' => true,
            'para_empresa' => false,
            'is_active' => true,
            'orden' => 100,
        ];
    }
}
