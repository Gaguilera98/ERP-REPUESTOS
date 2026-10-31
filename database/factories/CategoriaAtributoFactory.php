<?php

namespace Database\Factories;

use App\Enums\TipoAtributoCategoria;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaAtributo>
 */
class CategoriaAtributoFactory extends Factory
{
    protected $model = CategoriaAtributo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $etiqueta = fake()->unique()->words(2, true);

        return [
            'categoria_id' => Categoria::factory(),
            'clave' => str($etiqueta)->ascii()->snake()->toString(),
            'etiqueta' => ucfirst($etiqueta),
            'tipo' => TipoAtributoCategoria::Texto,
            'obligatorio' => false,
            'orden' => 0,
        ];
    }

    public function decimal(): static
    {
        return $this->state([
            'tipo' => TipoAtributoCategoria::Decimal,
            'opciones' => null,
        ]);
    }

    /**
     * @param  list<string>  $opciones
     */
    public function lista(array $opciones = ['2RS', 'ZZ']): static
    {
        return $this->state([
            'tipo' => TipoAtributoCategoria::Lista,
            'opciones' => $opciones,
            'unidad_medida_id' => null,
        ]);
    }
}
