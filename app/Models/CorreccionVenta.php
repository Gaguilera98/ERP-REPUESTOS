<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venta_id', 'user_id', 'caja_sesion_id', 'motivo', 'lineas', 'total'])]
class CorreccionVenta extends Model
{
    use BelongsToTenantPool;

    protected $table = 'correcciones_venta';

    protected function casts(): array
    {
        return ['lineas' => 'array', 'total' => 'decimal:2'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }
}
