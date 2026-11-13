<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'traslado_id',
    'producto_variante_id',
    'cantidad',
])]
class TrasladoDetalle extends Model
{
    use BelongsToTenantPool;

    protected $table = 'traslado_detalles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
        ];
    }

    public function traslado(): BelongsTo
    {
        return $this->belongsTo(Traslado::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }
}
