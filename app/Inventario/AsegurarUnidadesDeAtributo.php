<?php

namespace App\Inventario;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use App\Models\UnidadMedida;

class AsegurarUnidadesDeAtributo
{
    /**
     * Unidades para la ficha del rubro. No comparten símbolo con las de stock (cantidad).
     *
     * @var list<array{nombre: string, simbolo: string, magnitud: MagnitudUnidad}>
     */
    private const CATALOGO = [
        ['nombre' => 'Milímetro', 'simbolo' => 'mm', 'magnitud' => MagnitudUnidad::Longitud],
        ['nombre' => 'Pulgada', 'simbolo' => 'in', 'magnitud' => MagnitudUnidad::Longitud],
        ['nombre' => 'Voltio', 'simbolo' => 'V', 'magnitud' => MagnitudUnidad::Electrico],
        ['nombre' => 'Amperio', 'simbolo' => 'A', 'magnitud' => MagnitudUnidad::Electrico],
        ['nombre' => 'Bar', 'simbolo' => 'bar', 'magnitud' => MagnitudUnidad::Presion],
    ];

    public function handle(): void
    {
        foreach (self::CATALOGO as $definicion) {
            if (UnidadMedida::query()->where('nombre', $definicion['nombre'])->exists()) {
                continue;
            }

            UnidadMedida::query()->firstOrCreate(
                ['simbolo' => $definicion['simbolo']],
                [
                    'nombre' => $definicion['nombre'],
                    'magnitud' => $definicion['magnitud'],
                    'uso' => UsoUnidad::Atributo,
                ],
            );
        }
    }
}
