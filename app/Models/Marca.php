<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\MarcaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'is_active'])]
class Marca extends Model
{
    /** @use HasFactory<MarcaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'marcas';

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Marca $marca): void {
            if (! is_string($marca->nombre)) {
                return;
            }

            $marca->nombre = trim($marca->nombre);
        });
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoMaestro::class);
    }
}
