<?php

namespace App\Models;

use App\Enums\DevolucionComercialProducto;
use App\Inventario\AsegurarUnidadesDeCantidad;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ProductoMaestroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['tenant_id', 'nombre', 'categoria_id', 'marca_id', 'unidad_base_id', 'imagenes', 'is_active', 'devolucion_comercial'])]
class ProductoMaestro extends Model
{
    /** @use HasFactory<ProductoMaestroFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'productos_maestros';

    protected $attributes = [
        'is_active' => true,
        'devolucion_comercial' => DevolucionComercialProducto::Hereda,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imagenes' => 'array',
            'is_active' => 'boolean',
            'devolucion_comercial' => DevolucionComercialProducto::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductoMaestro $producto): void {
            if ($producto->unidad_base_id) {
                return;
            }

            app(AsegurarUnidadesDeCantidad::class)->handle();
            $producto->unidad_base_id = UnidadMedida::query()->where('simbolo', 'pza')->value('id');
        });
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProductoVariante::class);
    }

    /** @return HasManyThrough<InventarioStock, ProductoVariante, $this> */
    public function stocks(): HasManyThrough
    {
        return $this->through('variantes')->has('stocks');
    }

    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_base_id');
    }

    public function empaques(): HasMany
    {
        return $this->hasMany(ProductoUnidad::class);
    }
}
