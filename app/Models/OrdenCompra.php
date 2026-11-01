<?php

namespace App\Models;

use App\Compras\Montos;
use App\Enums\EstadoOrdenCompra;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\OrdenCompraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'proveedor_id',
    'proveedor_contacto_id',
    'user_id',
    'almacen_destino_id',
    'es_compra_flash',
    'estado',
    'subtotal',
    'porcentaje_impuesto',
    'impuestos',
    'total',
    'persona_entrega_nombre',
    'persona_recibe_trabajador_id',
    'fecha_emision',
    'fecha_recepcion',
])]
class OrdenCompra extends Model
{
    /** @use HasFactory<OrdenCompraFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'ordenes_compra';

    protected $attributes = [
        'es_compra_flash' => false,
        'estado' => 'pendiente',
        'impuestos' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_compra_flash' => 'boolean',
            'estado' => EstadoOrdenCompra::class,
            'subtotal' => 'decimal:2',
            'porcentaje_impuesto' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
            'fecha_emision' => 'datetime',
            'fecha_recepcion' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OrdenCompra $orden): void {
            if (! $orden->exists || ! $orden->detalles()->exists()) {
                $orden->impuestos = Montos::dos($orden->impuestos ?? '0');
                $orden->subtotal = Montos::dos($orden->subtotal ?? '0');
                $orden->total = Montos::sumar($orden->subtotal, $orden->impuestos, 2);

                return;
            }

            $subtotal = '0.00';

            foreach ($orden->detalles()->get() as $detalle) {
                $subtotal = Montos::sumar($subtotal, (string) $detalle->subtotal_linea, 2);
            }

            $orden->subtotal = $subtotal;
            $orden->impuestos = $orden->porcentaje_impuesto !== null
                ? Montos::porcentaje($subtotal, $orden->porcentaje_impuesto, 2)
                : Montos::dos($orden->impuestos ?? '0');
            $orden->total = Montos::sumar($orden->subtotal, $orden->impuestos, 2);
        });
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(ProveedorContacto::class, 'proveedor_contacto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    public function personaRecibe(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'persona_recibe_trabajador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenCompraDetalle::class);
    }
}
