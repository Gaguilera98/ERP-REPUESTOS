<?php

namespace Database\Factories;

use App\Enums\TipoAtributoCategoria;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoDocumentoAtributo>
 */
class TipoDocumentoAtributoFactory extends Factory
{
    protected $model = TipoDocumentoAtributo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_documento_identidad_id' => TipoDocumentoIdentidad::factory(),
            'clave' => fake()->unique()->lexify('campo_????'),
            'etiqueta' => fake()->words(2, true),
            'tipo' => TipoAtributoCategoria::Texto,
            'obligatorio' => false,
            'identifica' => false,
            'orden' => 10,
            'opciones' => null,
        ];
    }

    public function lista(array $opciones): static
    {
        return $this->state(fn (): array => [
            'tipo' => TipoAtributoCategoria::Lista,
            'opciones' => $opciones,
        ]);
    }
}
