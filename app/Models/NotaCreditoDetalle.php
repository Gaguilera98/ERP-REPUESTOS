<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nota_credito_id', 'venta_detalle_id', 'linea_indice', 'producto_variante_id', 'cantidad', 'cantidad_base', 'importe', 'linea_snapshot'])]
class NotaCreditoDetalle extends Model
{
    use BelongsToTenantPool;

    protected $table = 'nota_credito_detalles';

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'cantidad_base' => 'decimal:4', 'importe' => 'decimal:2', 'linea_snapshot' => 'array'];
    }
}
