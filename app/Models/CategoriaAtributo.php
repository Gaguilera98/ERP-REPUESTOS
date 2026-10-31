<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use App\TipoAtributoCategoria;
use Database\Factories\CategoriaAtributoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'tenant_id',
    'categoria_id',
    'clave',
    'etiqueta',
    'tipo',
    'obligatorio',
    'orden',
    'unidad_medida_id',
    'opciones',
])]
class CategoriaAtributo extends Model
{
    /** @use HasFactory<CategoriaAtributoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'categoria_atributos';

    protected $attributes = [
        'obligatorio' => false,
        'orden' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (CategoriaAtributo $atributo): void {
            $tipo = $atributo->tipo instanceof TipoAtributoCategoria
                ? $atributo->tipo
                : TipoAtributoCategoria::from((string) $atributo->tipo);

            if ($tipo !== TipoAtributoCategoria::Decimal) {
                $atributo->unidad_medida_id = null;
            }

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

            $anteriorId = $atributo->getOriginal('unidad_medida_id');

            if (! $anteriorId || (int) $anteriorId === (int) $atributo->unidad_medida_id) {
                return;
            }

            $anterior = UnidadMedida::query()->find($anteriorId);
            $nueva = UnidadMedida::query()->find($atributo->unidad_medida_id);

            if ($anterior && $nueva && $anterior->magnitud !== $nueva->magnitud) {
                throw ValidationException::withMessages([
                    'unidad_medida_id' => 'La unidad nueva tiene que ser de la misma magnitud.',
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoAtributoCategoria::class,
            'obligatorio' => 'boolean',
            'opciones' => 'array',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    public function agregarOpcion(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw ValidationException::withMessages([
                'valor' => 'Escribí el valor.',
            ]);
        }

        if (mb_strlen($valor) > 255) {
            throw ValidationException::withMessages([
                'valor' => 'El valor no puede pasar de 255 caracteres.',
            ]);
        }

        $opciones = array_map(strval(...), $this->opciones ?? []);

        foreach ($opciones as $opcion) {
            if (mb_strtolower($opcion) === mb_strtolower($valor)) {
                throw ValidationException::withMessages([
                    'valor' => 'Ya existe un valor con ese nombre.',
                ]);
            }
        }

        $opciones[] = $valor;
        $this->opciones = $opciones;
        $this->save();

        return $valor;
    }
}
