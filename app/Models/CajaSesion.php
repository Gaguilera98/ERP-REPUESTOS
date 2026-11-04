<?php

namespace App\Models;

use App\Enums\EstadoCajaSesion;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\CajaSesionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'sucursal_id',
    'user_id',
    'estado_sesion',
    'fecha_apertura',
    'fecha_cierre',
    'monto_apertura',
    'monto_cierre_teorico',
    'monto_cierre_real',
    'diferencia',
    'justificacion_diferencia',
    'supervisor_id',
    'caja_dia_id',
])]
class CajaSesion extends Model
{
    /** @use HasFactory<CajaSesionFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'cajas_sesiones';

    protected $attributes = [
        'estado_sesion' => 'abierta',
        'diferencia' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado_sesion' => EstadoCajaSesion::class,
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
            'monto_apertura' => 'decimal:2',
            'monto_cierre_teorico' => 'decimal:2',
            'monto_cierre_real' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function cajaDia(): BelongsTo
    {
        return $this->belongsTo(CajaDia::class, 'caja_dia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'supervisor_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'caja_sesion_id');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(CajaSesionMedio::class, 'caja_sesion_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado_sesion === EstadoCajaSesion::Abierta;
    }

    public function aceptaConteo(): bool
    {
        return in_array($this->estado_sesion, [
            EstadoCajaSesion::Abierta,
            EstadoCajaSesion::PendienteArqueo,
        ], true);
    }

    public function esDeHoy(): bool
    {
        return $this->fecha_apertura?->isSameDay(now()) ?? false;
    }
}
