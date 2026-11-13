<?php

namespace App\Models;

use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\SentidoCajaChica;
use App\Enums\TipoCajaChicaMovimiento;
use App\Enums\TipoComprobanteCajaChica;
use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'sucursal_id',
    'caja_sesion_id',
    'fondo_id',
    'categoria_gasto_id',
    'registrado_por_user_id',
    'rendido_por_user_id',
    'seguimiento_user_id',
    'autorizado_por_user_id',
    'orden_compra_id',
    'movimiento_origen_id',
    'tipo',
    'sentido',
    'estado',
    'afecta_saldo',
    'monto',
    'monto_gasto',
    'monto_sobrante',
    'concepto',
    'persona_recibe',
    'procedencia',
    'tipo_comprobante',
    'numero_comprobante',
    'comprobante_archivo_path',
    'motivo_excepcion',
    'idempotency_key',
])]
class CajaChicaMovimiento extends Model
{
    use BelongsToTenantPool;

    protected $table = 'caja_chica_movimientos';

    protected $attributes = [
        'afecta_saldo' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCajaChicaMovimiento::class,
            'sentido' => SentidoCajaChica::class,
            'estado' => EstadoCajaChicaMovimiento::class,
            'tipo_comprobante' => TipoComprobanteCajaChica::class,
            'afecta_saldo' => 'boolean',
            'monto' => 'decimal:2',
            'monto_gasto' => 'decimal:2',
            'monto_sobrante' => 'decimal:2',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
    }

    public function fondo(): BelongsTo
    {
        return $this->belongsTo(CajaChicaFondo::class, 'fondo_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'registrado_por_user_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'movimiento_origen_id');
    }

    public function movimientosDerivados(): HasMany
    {
        return $this->hasMany(self::class, 'movimiento_origen_id');
    }

    public function rendidoPor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'rendido_por_user_id');
    }

    public function valeRelacionadoId(): ?int
    {
        return match ($this->tipo) {
            TipoCajaChicaMovimiento::EgresoVale => $this->id,
            TipoCajaChicaMovimiento::IngresoSobrante => $this->movimiento_origen_id,
            default => null,
        };
    }

    public function importeVisible(string $moneda): string
    {
        $signo = $this->afecta_saldo ? ($this->sentido === SentidoCajaChica::Entrada ? '+ ' : '− ') : '';

        return $signo.$moneda.' '.$this->monto;
    }

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }
}
