<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\TipoDocumentoIdentidadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'codigo',
    'nombre',
    'para_persona_natural',
    'para_empresa',
    'is_active',
    'orden',
])]
class TipoDocumentoIdentidad extends Model
{
    /** @use HasFactory<TipoDocumentoIdentidadFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'tipos_documento_identidad';

    protected $attributes = [
        'para_persona_natural' => true,
        'para_empresa' => false,
        'is_active' => true,
        'orden' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'para_persona_natural' => 'boolean',
            'para_empresa' => 'boolean',
            'is_active' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return HasMany<TipoDocumentoAtributo, $this>
     */
    public function atributos(): HasMany
    {
        return $this->hasMany(TipoDocumentoAtributo::class, 'tipo_documento_identidad_id')
            ->orderBy('orden')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ClienteDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(ClienteDocumento::class, 'tipo_documento_identidad_id');
    }

    protected static function booted(): void
    {
        static::saving(function (TipoDocumentoIdentidad $tipo): void {
            if (is_string($tipo->codigo)) {
                $tipo->codigo = strtolower(trim($tipo->codigo));
            }

            if (is_string($tipo->nombre)) {
                $tipo->nombre = trim($tipo->nombre);
            }
        });
    }
}
