<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'user_id', 'almacen_id', 'destino', 'huella', 'archivo', 'moneda', 'estado', 'actualizar_existentes', 'fecha_inventario', 'filas', 'revision', 'resultado', 'completada_at'])]
class ImportacionProducto extends Model
{
    use BelongsToTenantPool;

    protected $table = 'importaciones_productos';

    protected function casts(): array
    {
        return [
            'filas' => 'array', 'revision' => 'array', 'resultado' => 'array',
            'actualizar_existentes' => 'boolean', 'fecha_inventario' => 'date', 'completada_at' => 'datetime',
        ];
    }

    public function codigo(): string
    {
        return ($this->almacen_id ? 'INI-' : 'IMP-').str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(InventarioInicialDetalle::class, 'importacion_id');
    }
}
