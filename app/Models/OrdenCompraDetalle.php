<?php

namespace App\Models;

use App\Catalogo\PreciosDeReferencia;
use App\Compras\MontoEnPalabras;
use App\Compras\Montos;
use App\Enums\MonedaLineaCompra;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\OrdenCompraDetalleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'orden_compra_id',
    'producto_variante_id',
    'cantidad_pedida',
    'cantidad_recibida',
    'unidad_medida_id',
    'factor_a_base',
    'cantidad_base',
    'costo_anotado',
    'moneda',
    'costo_unitario_origen',
    'tipo_cambio_id',
    'tipo_cambio_valor',
    'costo_unitario',
    'subtotal_linea',
])]
class OrdenCompraDetalle extends Model
{
    /** @use HasFactory<OrdenCompraDetalleFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'orden_compra_detalles';

    protected $attributes = [
        'cantidad_recibida' => 0,
        'factor_a_base' => 1,
        'moneda' => 'local',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad_pedida' => 'decimal:4',
            'cantidad_recibida' => 'decimal:4',
            'factor_a_base' => 'decimal:6',
            'cantidad_base' => 'decimal:4',
            'costo_anotado' => 'decimal:4',
            'moneda' => MonedaLineaCompra::class,
            'costo_unitario_origen' => 'decimal:4',
            'tipo_cambio_valor' => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'subtotal_linea' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OrdenCompraDetalle $detalle): void {
            $cambio = null;
            if ($detalle->moneda === MonedaLineaCompra::Usd) {
                $cambio = app(PreciosDeReferencia::class)->validarCotizacion($detalle->tipo_cambio_valor, 'tipo_cambio_valor');
            } else {
                $detalle->moneda = MonedaLineaCompra::Local;
                $detalle->tipo_cambio_id = null;
            }

            $factor = (string) ($detalle->factor_a_base ?: '1');
            $anotado = Montos::cuatro($detalle->costo_anotado ?? $detalle->costo_unitario);
            $detalle->costo_anotado = $anotado;
            $detalle->factor_a_base = $factor;
            $detalle->costo_unitario_origen = $anotado;
            $local = $cambio === null ? $anotado : Montos::multiplicar($anotado, $cambio, 4);
            $detalle->costo_unitario = Montos::dividir($local, $factor, 4);
            $detalle->cantidad_base = Montos::multiplicar((string) $detalle->cantidad_pedida, $factor, 4);
            $detalle->tipo_cambio_valor = $cambio;
            $detalle->subtotal_linea = Montos::multiplicar((string) $detalle->cantidad_pedida, $local, 2);
        });
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function ultimaCorreccionCosto(): HasOne
    {
        return $this->hasOne(CorreccionCosto::class, 'orden_compra_detalle_id')->latestOfMany();
    }

    public function costoVigente(): string
    {
        return (string) ($this->ultimaCorreccionCosto?->costo_nuevo ?? $this->costo_unitario);
    }

    public function textoCostoPorUnidad(string $moneda): string
    {
        $simbolo = $this->variante?->productoMaestro?->unidadBase?->simbolo ?? 'pza';

        return 'Costo por '.$simbolo.': '.MontoEnPalabras::numerosYLetras($this->costoVigente(), $moneda).'.';
    }
}
