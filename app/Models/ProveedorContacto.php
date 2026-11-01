<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ProveedorContactoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'proveedor_id',
    'nombres',
    'apellidos',
    'cargo',
    'telefono',
    'email',
    'es_principal',
])]
class ProveedorContacto extends Model
{
    /** @use HasFactory<ProveedorContactoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'proveedor_contactos';

    protected $attributes = [
        'es_principal' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProveedorContacto $contacto): void {
            foreach (['nombres', 'apellidos'] as $campo) {
                $valor = $contacto->getAttribute($campo);

                if (is_string($valor)) {
                    $contacto->setAttribute($campo, trim($valor));
                }
            }

            if (! $contacto->es_principal || $contacto->proveedor_id === null) {
                return;
            }

            $contacto->newQuery()
                ->where('proveedor_id', $contacto->proveedor_id)
                ->when(
                    $contacto->exists,
                    fn (Builder $query) => $query->whereKeyNot($contacto->getKey()),
                )
                ->update(['es_principal' => false]);
        });
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }
}
