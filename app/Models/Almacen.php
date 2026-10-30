<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\AlmacenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'nombre'])]
class Almacen extends Model
{
    /** @use HasFactory<AlmacenFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'almacenes';

    public function sucursales(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'sucursal_almacenes')
            ->using(SucursalAlmacen::class)
            ->withPivot(['es_principal', 'tenant_id'])
            ->withTimestamps();
    }
}
