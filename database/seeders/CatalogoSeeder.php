<?php

namespace Database\Seeders;

use App\Enums\MagnitudUnidad;
use App\Enums\TipoAtributoCategoria;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

class CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $milimetro = $this->unidad('Milímetro', 'mm', MagnitudUnidad::Longitud);
        $this->unidad('Centímetro', 'cm', MagnitudUnidad::Longitud);
        $this->unidad('Metro', 'm', MagnitudUnidad::Longitud);
        $this->unidad('Pulgada', 'in', MagnitudUnidad::Longitud);
        $this->unidad('Voltio', 'V', MagnitudUnidad::Electrico);
        $this->unidad('Amperio', 'A', MagnitudUnidad::Electrico);
        $this->unidad('Bar', 'bar', MagnitudUnidad::Presion);

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

    private function unidad(string $nombre, string $simbolo, MagnitudUnidad $magnitud): UnidadMedida
    {
        return UnidadMedida::query()->updateOrCreate(
            ['nombre' => $nombre],
            [
                'simbolo' => $simbolo,
                'magnitud' => $magnitud,
            ],
        );
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
