<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\SucursalAlmacenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['tenant_id', 'sucursal_id', 'almacen_id', 'es_principal'])]
class SucursalAlmacen extends Pivot
{
    /** @use HasFactory<SucursalAlmacenFactory> */
    use BelongsToTenantPool, HasFactory;

    public $incrementing = true;

    protected $table = 'sucursal_almacenes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SucursalAlmacen $asignacion): void {
            if (! $asignacion->es_principal || $asignacion->sucursal_id === null) {
                return;
            }

            $asignacion->newQuery()
                ->where('sucursal_id', $asignacion->sucursal_id)
                ->when(
                    $asignacion->exists,
                    fn (Builder $query) => $query->whereKeyNot($asignacion->getKey()),
                )
                ->update(['es_principal' => false]);
        });
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }
}
