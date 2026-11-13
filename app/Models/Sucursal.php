<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\SucursalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'direccion', 'telefono', 'is_active'])]
class Sucursal extends Model
{
    /** @use HasFactory<SucursalFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'sucursales';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(SucursalAlmacen::class);
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(TenantUser::class, 'user_sucursales', 'sucursal_id', 'user_id')
            ->withTimestamps();
    }
}
