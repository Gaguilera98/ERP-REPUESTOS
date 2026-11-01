<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\PagoAplicacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'pago_id',
    'venta_id',
    'nota_credito_id',
    'aplicacion_origen_id',
    'monto',
])]
class PagoAplicacion extends Model
{
    /** @use HasFactory<PagoAplicacionFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'pago_aplicaciones';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function aplicacionOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'aplicacion_origen_id');
    }
}
