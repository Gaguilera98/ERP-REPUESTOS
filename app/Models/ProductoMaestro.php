<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ProductoMaestroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'categoria_id', 'marca_id', 'imagenes', 'is_active'])]
class ProductoMaestro extends Model
{
    /** @use HasFactory<ProductoMaestroFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'productos_maestros';

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imagenes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProductoVariante::class);
    }
}
