<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'orden_compra_detalle_id',
    'producto_variante_id',
    'almacen_id',
    'user_id',
    'supervisor_id',
    'costo_anterior',
    'costo_nuevo',
    'promedio_anterior',
    'promedio_nuevo',
    'observacion',
])]
class CorreccionCosto extends Model
{
    use BelongsToTenantPool;

    protected $table = 'correcciones_costo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'costo_anterior' => 'decimal:4',
            'costo_nuevo' => 'decimal:4',
            'promedio_anterior' => 'decimal:4',
            'promedio_nuevo' => 'decimal:4',
        ];
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(OrdenCompraDetalle::class, 'orden_compra_detalle_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }
}
