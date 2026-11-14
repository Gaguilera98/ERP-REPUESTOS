<?php

namespace Database\Seeders;

use App\Enums\TipoAtributoCategoria;
use App\Enums\UsoUnidad;
use App\Inventario\AsegurarUnidadesDeAtributo;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

class CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        app(AsegurarUnidadesDeAtributo::class)->handle();

        $milimetro = UnidadMedida::query()
            ->where('simbolo', 'mm')
            ->where('uso', UsoUnidad::Atributo)
            ->firstOrFail();

        $rodamientos = Categoria::query()->updateOrCreate(
            ['nombre' => 'Rodamientos'],
        );

        $this->atributo($rodamientos, 'diametro_interno', 'Diámetro interno', TipoAtributoCategoria::Decimal, true, 1, $milimetro);
        $this->atributo($rodamientos, 'diametro_externo', 'Diámetro externo', TipoAtributoCategoria::Decimal, true, 2, $milimetro);
        $this->atributo($rodamientos, 'ancho', 'Ancho', TipoAtributoCategoria::Decimal, true, 3, $milimetro);
        $this->atributo($rodamientos, 'sello', 'Sello', TipoAtributoCategoria::Lista, false, 4, null, ['2RS', 'ZZ', 'ABIERTO']);

        CategoriaAtributo::query()
            ->where('categoria_id', $rodamientos->id)
            ->where('clave', 'marca')
            ->delete();
    }

    /**
     * @param  list<string>|null  $opciones
     */
    private function atributo(
        Categoria $categoria,
        string $clave,
        string $etiqueta,
        TipoAtributoCategoria $tipo,
        bool $obligatorio,
        int $orden,
        ?UnidadMedida $unidad = null,
        ?array $opciones = null,
    ): void {
        CategoriaAtributo::query()->updateOrCreate(
            [
                'categoria_id' => $categoria->id,
                'clave' => $clave,
            ],
            [
                'etiqueta' => $etiqueta,
                'tipo' => $tipo,
                'obligatorio' => $obligatorio,
                'orden' => $orden,
                'unidad_medida_id' => $unidad?->id,
                'opciones' => $opciones,
            ],
        );
    }
}
