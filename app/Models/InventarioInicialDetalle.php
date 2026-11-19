<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'importacion_id', 'producto_variante_id', 'proveedor_id', 'fila', 'cantidad', 'costo_unitario'])]
class InventarioInicialDetalle extends Model
{
    use BelongsToTenantPool;

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'costo_unitario' => 'decimal:4'];
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ImportacionProducto::class, 'importacion_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }
}
