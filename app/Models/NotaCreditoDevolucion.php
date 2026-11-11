<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['venta_id', 'venta_reemplazo_id', 'user_id', 'caja_sesion_id', 'motivo', 'motivo_excepcion', 'resolucion', 'total', 'deuda_cancelada', 'importe_resuelto', 'revision'])]
class NotaCreditoDevolucion extends Model
{
    use BelongsToTenantPool;

    protected $table = 'notas_credito_devoluciones';

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'deuda_cancelada' => 'decimal:2', 'importe_resuelto' => 'decimal:2'];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(NotaCreditoDetalle::class, 'nota_credito_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function ventaOriginal(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function ventaReemplazo(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_reemplazo_id');
    }
}
