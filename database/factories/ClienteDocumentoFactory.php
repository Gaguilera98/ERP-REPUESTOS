<?php

namespace Database\Factories;

use App\Enums\TipoAtributoCategoria;
use App\Models\Cliente;
use App\Models\ClienteDocumento;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteDocumento>
 */
class ClienteDocumentoFactory extends Factory
{
    protected $model = ClienteDocumento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'tipo_documento_identidad_id' => function (): int {
                $tipo = TipoDocumentoIdentidad::factory()->create();

                TipoDocumentoAtributo::factory()->create([
                    'tipo_documento_identidad_id' => $tipo->id,
                    'clave' => 'numero',
                    'etiqueta' => 'Número',
                    'tipo' => TipoAtributoCategoria::Texto,
                    'obligatorio' => true,
                    'identifica' => true,
                    'orden' => 10,
                ]);

                return $tipo->id;
            },
            'valores_json' => [
                'numero' => (string) fake()->unique()->numerify('#######'),
            ],
        ];
    }
}
