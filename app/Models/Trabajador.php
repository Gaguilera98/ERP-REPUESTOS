<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\TrabajadorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombres', 'apellidos', 'cedula', 'celular', 'user_id', 'is_active'])]
class Trabajador extends Model
{
    /** @use HasFactory<TrabajadorFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'trabajadores';

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Trabajador $trabajador): void {
            foreach (['nombres', 'apellidos'] as $campo) {
                $valor = $trabajador->getAttribute($campo);

                if (is_string($valor)) {
                    $trabajador->setAttribute($campo, trim($valor));
                }
            }

            foreach (['cedula', 'celular'] as $campo) {
                $valor = $trabajador->getAttribute($campo);
                $trabajador->setAttribute(
                    $campo,
                    is_string($valor) && trim($valor) !== '' ? trim($valor) : null,
                );
            }
        });
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function ordenesRecibidas(): HasMany
    {
        return $this->hasMany(OrdenCompra::class, 'persona_recibe_trabajador_id');
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }
}
