<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\CajaSesionMedioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'caja_sesion_id',
    'metodo_pago',
    'monto_esperado',
    'monto_declarado',
    'diferencia',
])]
class CajaSesionMedio extends Model
{
    /** @use HasFactory<CajaSesionMedioFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'caja_sesion_medios';

    protected $attributes = [
        'diferencia' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metodo_pago' => MetodoPago::class,
            'monto_esperado' => 'decimal:2',
            'monto_declarado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    public function cajaSesion(): BelongsTo
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
    }
}
