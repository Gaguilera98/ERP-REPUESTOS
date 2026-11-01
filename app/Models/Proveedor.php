<?php

namespace App\Models;

use App\Exceptions\Compras\ProveedorOcasionalProtegidoException;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'nombre_empresa', 'nit', 'es_ocasional', 'direccion'])]
class Proveedor extends Model
{
    public const OCASIONAL = 'Compra Ocasional / Proveedor Local';

    /** @use HasFactory<ProveedorFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'proveedores';

    protected $attributes = [
        'es_ocasional' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_ocasional' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Proveedor $proveedor): void {
            $nit = $proveedor->nit;
            $proveedor->nit = is_string($nit) && trim($nit) !== '' ? trim($nit) : null;

            if (is_string($proveedor->nombre_empresa)) {
                $proveedor->nombre_empresa = trim($proveedor->nombre_empresa);
            }

            if ($proveedor->exists && (bool) $proveedor->getOriginal('es_ocasional')) {
                $proveedor->es_ocasional = true;
                $proveedor->nombre_empresa = self::OCASIONAL;
            }
        });

        static::deleting(function (Proveedor $proveedor): void {
            if ($proveedor->es_ocasional) {
                throw new ProveedorOcasionalProtegidoException;
            }
        });
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(ProveedorContacto::class);
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }
}
