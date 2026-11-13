<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'almacen_origen_id',
    'almacen_destino_id',
    'user_id',
    'estado',
    'observacion',
])]
class Traslado extends Model
{
    use BelongsToTenantPool;

    protected $table = 'traslados';

    protected $attributes = [
        'estado' => 'completado',
    ];

    public function codigo(): string
    {
        return sprintf('TR-%06d', $this->id);
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(TrasladoDetalle::class, 'traslado_id');
    }
}
