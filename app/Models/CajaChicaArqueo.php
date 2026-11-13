<?php

namespace App\Models;

use App\Enums\TipoCajaChicaArqueo;
use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'fondo_id',
    'tipo',
    'teorico',
    'contado',
    'diferencia',
    'justificacion',
    'user_id',
    'entrega_user_id',
    'recibe_user_id',
])]
class CajaChicaArqueo extends Model
{
    use BelongsToTenantPool;

    protected $table = 'caja_chica_arqueos';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCajaChicaArqueo::class,
            'teorico' => 'decimal:2',
            'contado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    public function fondo(): BelongsTo
    {
        return $this->belongsTo(CajaChicaFondo::class, 'fondo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'entrega_user_id');
    }

    public function recibe(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'recibe_user_id');
    }
}
