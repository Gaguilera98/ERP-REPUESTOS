<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ImpuestoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'nombre', 'porcentaje', 'is_active'])]
class Impuesto extends Model
{
    /** @use HasFactory<ImpuestoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'impuestos';

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Impuesto $impuesto): void {
            if (is_string($impuesto->nombre)) {
                $impuesto->nombre = trim($impuesto->nombre);
            }
        });
    }
}
