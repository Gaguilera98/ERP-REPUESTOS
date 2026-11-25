<?php

namespace App\Models;

use App\Casts\AtributosJsonCast;
use App\Catalogo\AsegurarStockEnCero;
use App\Catalogo\NormalizarAtributosVariante;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ProductoVarianteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'producto_maestro_id',
    'condicion_producto_id',
    'sku',
    'codigo_barras',
    'imagen',
    'atributos_json',
    'precio_venta',
    'precio_mayor',
    'precio_venta_dolar',
    'precio_mayor_dolar',
    'precio_costo',
    'is_active',
])]
class ProductoVariante extends Model
{
    /** @use HasFactory<ProductoVarianteFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'productos_variantes';

    protected $attributes = [
        'precio_costo' => 0,
        'atributos_json' => '{}',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductoVariante $variante): void {
            if (! $variante->producto_maestro_id) {
                return;
            }

            $variante->atributos_json = app(NormalizarAtributosVariante::class)->para($variante);
        });

        static::created(function (ProductoVariante $variante): void {
            app(AsegurarStockEnCero::class)->paraVariante($variante);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'atributos_json' => AtributosJsonCast::class,
            'precio_venta' => 'decimal:2',
            'precio_mayor' => 'decimal:2',
            'precio_venta_dolar' => 'decimal:2',
            'precio_mayor_dolar' => 'decimal:2',
            'precio_costo' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function productoMaestro(): BelongsTo
    {
        return $this->belongsTo(ProductoMaestro::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventarioStock::class);
    }

    public function condicion(): BelongsTo
    {
        return $this->belongsTo(CondicionProducto::class, 'condicion_producto_id');
    }
}
