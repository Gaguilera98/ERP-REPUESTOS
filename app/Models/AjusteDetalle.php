<?php

namespace App\Models;

use App\Enums\MotivoAjusteInventario;
use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'ajuste_id',
    'producto_variante_id',
    'motivo',
    'cantidad_sistema',
    'cantidad_fisica',
])]
class AjusteDetalle extends Model
{
    use BelongsToTenantPool;

    protected $table = 'ajuste_detalles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'motivo' => MotivoAjusteInventario::class,
            'cantidad_sistema' => 'decimal:4',
            'cantidad_fisica' => 'decimal:4',
        ];
    }

    public function ajuste(): BelongsTo
    {
        return $this->belongsTo(AjusteInventario::class, 'ajuste_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }
}
