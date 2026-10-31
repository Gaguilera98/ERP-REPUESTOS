<?php

namespace App\Models;

use App\Enums\MagnitudUnidad;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\UnidadMedidaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre', 'simbolo', 'magnitud'])]
class UnidadMedida extends Model
{
    /** @use HasFactory<UnidadMedidaFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'unidades_medida';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'magnitud' => MagnitudUnidad::class,
        ];
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(CategoriaAtributo::class);
    }
}
