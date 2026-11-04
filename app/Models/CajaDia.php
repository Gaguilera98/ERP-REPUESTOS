<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\CajaDiaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'sucursal_id',
    'fecha',
    'user_id',
    'fecha_cierre',
])]
class CajaDia extends Model
{
    /** @use HasFactory<CajaDiaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'cajas_dias';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(CajaSesion::class, 'caja_dia_id');
    }
}
