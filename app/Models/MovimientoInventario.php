<?php

namespace App\Models;

use App\Enums\TipoMovimientoInventario;
use App\Exceptions\Compras\KardexInmutableException;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\MovimientoInventarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'producto_variante_id',
    'almacen_id',
    'user_id',
    'tipo_movimiento',
    'cantidad',
    'stock_previo',
    'stock_posterior',
    'costo_unitario',
    'costo_promedio_resultante',
    'referencia_tipo',
    'referencia_id',
])]
class MovimientoInventario extends Model
{
    public const REFERENCIA_ORDEN_COMPRA = 'orden_compra';

    /** @use HasFactory<MovimientoInventarioFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'movimientos_inventario';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_movimiento' => TipoMovimientoInventario::class,
            'cantidad' => 'decimal:4',
            'stock_previo' => 'decimal:4',
            'stock_posterior' => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'costo_promedio_resultante' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new KardexInmutableException;
        });

        static::deleting(function (): void {
            throw new KardexInmutableException;
        });
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }
}
