<?php

namespace App\Tenancy;

use App\Enums\DepartamentoEmisionBolivia;
use App\Enums\TipoAtributoCategoria;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;

class AsegurarTiposDocumentoIdentidad
{
    public function handle(): void
    {
        $ci = TipoDocumentoIdentidad::query()->firstOrCreate(
            ['codigo' => 'ci'],
            [
                'nombre' => 'Cédula de identidad',
                'para_persona_natural' => true,
                'para_empresa' => false,
                'is_active' => true,
                'orden' => 10,
            ],
        );

        $this->asegurarAtributo($ci, 'numero', [
            'etiqueta' => 'Número',
            'tipo' => TipoAtributoCategoria::Texto,
            'obligatorio' => true,
            'identifica' => true,
            'orden' => 10,
            'opciones' => null,
        ]);

        $this->asegurarAtributo($ci, 'complemento', [
            'etiqueta' => 'Complemento',
            'tipo' => TipoAtributoCategoria::Texto,
            'obligatorio' => false,
            'identifica' => true,
            'orden' => 20,
            'opciones' => null,
        ]);

        $this->asegurarAtributo($ci, 'lugar_emision', [
            'etiqueta' => 'Lugar de emisión',
            'tipo' => TipoAtributoCategoria::Lista,
            'obligatorio' => true,
            'identifica' => false,
            'orden' => 30,
            'opciones' => collect(DepartamentoEmisionBolivia::cases())
                ->map(fn (DepartamentoEmisionBolivia $depto): string => $depto->value)
                ->values()
                ->all(),
        ]);

        $nit = TipoDocumentoIdentidad::query()->firstOrCreate(
            ['codigo' => 'nit'],
            [
                'nombre' => 'NIT',
                'para_persona_natural' => true,
                'para_empresa' => true,
                'is_active' => true,
                'orden' => 20,
            ],
        );

        $this->asegurarAtributo($nit, 'numero', [
            'etiqueta' => 'Número',
            'tipo' => TipoAtributoCategoria::Texto,
            'obligatorio' => true,
            'identifica' => true,
            'orden' => 10,
            'opciones' => null,
        ]);
    }

    /**
     * @param  array{
     *     etiqueta: string,
     *     tipo: TipoAtributoCategoria,
     *     obligatorio: bool,
     *     identifica: bool,
     *     orden: int,
     *     opciones: list<string>|null
     * }  $definicion
     */
    private function asegurarAtributo(TipoDocumentoIdentidad $tipo, string $clave, array $definicion): void
    {
        TipoDocumentoAtributo::query()->firstOrCreate(
            [
                'tipo_documento_identidad_id' => $tipo->id,
                'clave' => $clave,
            ],
            [
                'etiqueta' => $definicion['etiqueta'],
                'tipo' => $definicion['tipo'],
                'obligatorio' => $definicion['obligatorio'],
                'identifica' => $definicion['identifica'],
                'orden' => $definicion['orden'],
                'opciones' => $definicion['opciones'],
            ],
        );
    }
}
