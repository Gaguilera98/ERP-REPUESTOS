<?php

namespace App\Models;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\UnidadMedidaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'simbolo', 'magnitud', 'uso', 'factor_a_referencia', 'decimales', 'es_referencia'])]
class UnidadMedida extends Model
{
    /** @use HasFactory<UnidadMedidaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'unidades_medida';

    protected $attributes = [
        'uso' => 'atributo',
        'factor_a_referencia' => 1,
        'decimales' => 0,
        'es_referencia' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'magnitud' => MagnitudUnidad::class,
            'uso' => UsoUnidad::class,
            'factor_a_referencia' => 'decimal:6',
            'decimales' => 'integer',
            'es_referencia' => 'boolean',
        ];
    }

    public function admiteCantidad(string $cantidad): bool
    {
        if ($this->decimales > 0) {
            return true;
        }

        return preg_match('/^\d+(\.0+)?$/', $cantidad) === 1;
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(CategoriaAtributo::class);
    }
}
