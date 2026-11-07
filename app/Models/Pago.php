<?php

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\PagoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'cliente_id',
    'caja_sesion_id',
    'sentido',
    'metodo_pago',
    'monto',
    'comprobante_archivo_path',
    'numero_referencia_banco',
    'qr_id_transaccion_banco',
    'estado_pago',
    'fecha_pago',
    'registrado_por_id',
    'motivo',
])]
class Pago extends Model
{
    /** @use HasFactory<PagoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'pagos';

    protected $attributes = [
        'sentido' => 'cobro',
        'estado_pago' => 'aprobado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sentido' => SentidoPago::class,
            'metodo_pago' => MetodoPago::class,
            'monto' => 'decimal:2',
            'estado_pago' => EstadoPago::class,
            'fecha_pago' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function cajaSesion(): BelongsTo
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'registrado_por_id');
    }
}
