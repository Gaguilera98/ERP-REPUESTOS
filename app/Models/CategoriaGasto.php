<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'descripcion', 'is_active'])]
class CategoriaGasto extends Model
{
    use BelongsToTenantPool;

    protected $table = 'categorias_gastos';

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

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaChicaMovimiento::class, 'categoria_gasto_id');
    }
}
