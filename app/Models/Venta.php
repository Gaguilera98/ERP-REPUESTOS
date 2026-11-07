<?php

namespace App\Models;

use App\Enums\CondicionVenta;
use App\Enums\EstadoPago;
use App\Enums\EstadoVenta;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoDescuento;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\VentaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'codigo',
    'sucursal_id',
    'almacen_id',
    'talonario_id',
    'cliente_id',
    'user_id',
    'caja_sesion_id',
    'condicion',
    'estado',
    'vence_en',
    'tipo_comprobante',
    'numero_comprobante',
    'subtotal',
    'tipo_descuento',
    'valor_descuento',
    'descuento',
    'supervisor_descuento_id',
    'total',
    'notes_internas',
])]
class Venta extends Model
{
    /** @use HasFactory<VentaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'ventas';

    protected $attributes = [
        'condicion' => 'contado',
        'estado' => 'pendiente',
        'valor_descuento' => 0,
        'descuento' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condicion' => CondicionVenta::class,
            'estado' => EstadoVenta::class,
            'vence_en' => 'datetime',
            'anulada_en' => 'datetime',
            'tipo_comprobante' => TipoComprobante::class,
            'subtotal' => 'decimal:2',
            'tipo_descuento' => TipoDescuento::class,
            'valor_descuento' => 'decimal:2',
            'descuento' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function cajaSesion(): BelongsTo
    {
        return $this->belongsTo(CajaSesion::class);
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'anulada_por_id');
    }

    public function supervisorDescuento(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'supervisor_descuento_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function aplicacionesPago(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(CorreccionVenta::class);
    }

    public function ultimaCorreccion(): HasOne
    {
        return $this->hasOne(CorreccionVenta::class)->latestOfMany();
    }

    public function totalVigente(): string
    {
        return $this->ultimaCorreccion?->total ?? $this->total;
    }

    public function cobradoNeto(): string
    {
        $pagos = $this->aplicacionesPago()->with('pago')
            ->whereHas('pago', fn ($query) => $query->where('estado_pago', EstadoPago::Aprobado))->get();

        return $pagos->reduce(fn (string $total, PagoAplicacion $aplicacion): string => $aplicacion->pago->sentido === SentidoPago::Cobro
            ? bcadd($total, $aplicacion->monto, 2)
            : bcsub($total, $aplicacion->monto, 2), '0.00');
    }

    public function saldoPendiente(): string
    {
        if ($this->estado === EstadoVenta::Anulada) {
            return '0.00';
        }

        return bcsub($this->totalVigente(), $this->cobradoNeto(), 2);
    }

    public function estaCompletada(): bool
    {
        return $this->estado === EstadoVenta::Completada;
    }
}
