<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'producto_maestro_id', 'unidad_medida_id', 'factor_a_base'])]
class ProductoUnidad extends Model
{
    use BelongsToTenantPool;

    protected $table = 'producto_unidades';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factor_a_base' => 'decimal:6',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoMaestro::class, 'producto_maestro_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }
}
