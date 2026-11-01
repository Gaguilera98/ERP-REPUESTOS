<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\InventarioStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'producto_variante_id', 'almacen_id', 'ubicacion'])]
class InventarioStock extends Model
{
    /** @use HasFactory<InventarioStockFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'inventario_stocks';

    protected $attributes = [
        'cantidad_actual' => 0,
        'cantidad_reservada' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (InventarioStock $stock): void {
            $stock->cantidad_actual = 0;
            $stock->cantidad_reservada = 0;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad_actual' => 'decimal:4',
            'cantidad_reservada' => 'decimal:4',
        ];
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'producto_variante_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }
}
