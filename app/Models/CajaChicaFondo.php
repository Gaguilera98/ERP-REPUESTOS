<?php

namespace App\Models;

use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'sucursal_id',
    'responsable_id',
    'monto_apertura',
    'saldo_actual',
    'estado',
])]
class CajaChicaFondo extends Model
{
    use BelongsToTenantPool;

    protected $table = 'caja_chica_fondos';

    protected $attributes = [
        'estado' => 'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_apertura' => 'decimal:2',
            'saldo_actual' => 'decimal:2',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'responsable_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaChicaMovimiento::class, 'fondo_id');
    }

    public function arqueos(): HasMany
    {
        return $this->hasMany(CajaChicaArqueo::class, 'fondo_id');
    }

    public function valesPendientes(): HasMany
    {
        return $this->movimientos()->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
            ->where('estado', EstadoCajaChicaMovimiento::PendienteRendir);
    }
}
