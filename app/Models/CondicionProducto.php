<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\CondicionProductoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'codigo', 'nombre', 'is_active', 'orden'])]
class CondicionProducto extends Model
{
    /** @use HasFactory<CondicionProductoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'condiciones_producto';

    protected $attributes = ['is_active' => true, 'orden' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'orden' => 'integer'];
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProductoVariante::class, 'condicion_producto_id');
    }
}
