<?php

namespace App\Models;

use App\Enums\TipoAtributoCategoria;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\TipoDocumentoAtributoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'tenant_id',
    'tipo_documento_identidad_id',
    'clave',
    'etiqueta',
    'tipo',
    'obligatorio',
    'identifica',
    'orden',
    'opciones',
])]
class TipoDocumentoAtributo extends Model
{
    /** @use HasFactory<TipoDocumentoAtributoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'tipo_documento_atributos';

    protected $attributes = [
        'obligatorio' => false,
        'identifica' => false,
        'orden' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoAtributoCategoria::class,
            'obligatorio' => 'boolean',
            'identifica' => 'boolean',
            'opciones' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TipoDocumentoIdentidad, $this>
     */
    public function tipoDocumentoIdentidad(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoIdentidad::class, 'tipo_documento_identidad_id');
    }

    protected static function booted(): void
    {
        static::saving(function (TipoDocumentoAtributo $atributo): void {
            if (is_string($atributo->clave)) {
                $atributo->clave = trim($atributo->clave);
            }

            if (is_string($atributo->etiqueta)) {
                $atributo->etiqueta = trim($atributo->etiqueta);
            }

            $tipo = $atributo->tipo instanceof TipoAtributoCategoria
                ? $atributo->tipo
                : TipoAtributoCategoria::from((string) $atributo->tipo);

            if ($tipo === TipoAtributoCategoria::Lista) {
                $opciones = array_values(array_filter(
                    (array) $atributo->opciones,
                    fn (mixed $opcion): bool => filled($opcion),
                ));

                if ($opciones === []) {
                    throw ValidationException::withMessages([
                        'opciones' => 'Una lista necesita al menos una opción.',
                    ]);
                }

                $atributo->opciones = $opciones;
            } else {
                $atributo->opciones = null;
            }
        });
    }
}
