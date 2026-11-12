<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'is_active', 'sin_devolucion_comercial'])]
class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'categorias';

    protected $attributes = [
        'is_active' => true,
        'sin_devolucion_comercial' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sin_devolucion_comercial' => 'boolean',
        ];
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(CategoriaAtributo::class)->orderBy('orden')->orderBy('id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoMaestro::class);
    }
}
