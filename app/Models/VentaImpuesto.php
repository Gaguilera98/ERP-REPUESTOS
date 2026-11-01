<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\VentaImpuestoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'venta_id',
    'nombre_impuesto_snapshot',
    'porcentaje_aplicado_snapshot',
    'monto_calculado',
])]
class VentaImpuesto extends Model
{
    /** @use HasFactory<VentaImpuestoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'venta_impuestos';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje_aplicado_snapshot' => 'decimal:2',
            'monto_calculado' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
