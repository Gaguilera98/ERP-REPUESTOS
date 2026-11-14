<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\TipoCambioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['moneda_local', 'valor', 'user_id'])]
class TipoCambio extends Model
{
    /** @use HasFactory<TipoCambioFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'tipos_cambio';

    protected function casts(): array
    {
        return ['valor' => 'decimal:4'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }
}
