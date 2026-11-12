<?php

namespace App\Models;

use App\Enums\TipoDescuento;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\VentaDetalleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'venta_id',
    'producto_variante_id',
    'unidad_medida_id',
    'factor_a_base',
    'cantidad',
    'cantidad_base',
    'precio_unitario',
    'precio_costo_snapshot',
    'tipo_descuento',
    'valor_descuento',
    'descuento_aplicado',
    'supervisor_descuento_id',
    'total_linea',
    'sin_devolucion_comercial',
    'motivo_sin_devolucion',
])]
class VentaDetalle extends Model
{
    /** @use HasFactory<VentaDetalleFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'venta_detalles';

    protected $attributes = [
        'valor_descuento' => 0,
        'descuento_aplicado' => 0,
        'sin_devolucion_comercial' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factor_a_base' => 'decimal:6',
            'cantidad' => 'decimal:4',
            'cantidad_base' => 'decimal:4',
            'precio_unitario' => 'decimal:2',
            'precio_costo_snapshot' => 'decimal:4',
            'tipo_descuento' => TipoDescuento::class,
            'valor_descuento' => 'decimal:2',
            'descuento_aplicado' => 'decimal:2',
            'total_linea' => 'decimal:2',
            'sin_devolucion_comercial' => 'boolean',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function supervisorDescuento(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'supervisor_descuento_id');
    }
}
