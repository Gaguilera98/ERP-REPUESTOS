<?php

namespace App\Models;

use App\Enums\EstadoAjusteInventario;
use App\Enums\TipoAjusteInventario;
use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'almacen_id',
    'user_id',
    'supervisor_id',
    'tipo',
    'estado',
    'observacion',
])]
class AjusteInventario extends Model
{
    use BelongsToTenantPool;

    protected $table = 'ajustes_inventario';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoAjusteInventario::class,
            'estado' => EstadoAjusteInventario::class,
        ];
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'supervisor_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(AjusteDetalle::class, 'ajuste_id');
    }
}
