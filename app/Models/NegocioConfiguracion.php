<?php

namespace App\Models;

use App\Enums\ModoMoneda;
use App\Enums\Moneda;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\NegocioConfiguracionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'horas_reserva',
    'descuento_max_porcentaje',
    'descuento_max_importe',
    'moneda',
    'modo_moneda',
    'siguiente_numero_venta',
])]
class NegocioConfiguracion extends Model
{
    /** @use HasFactory<NegocioConfiguracionFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'negocio_configuracion';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'horas_reserva' => 'integer',
            'descuento_max_porcentaje' => 'decimal:2',
            'descuento_max_importe' => 'decimal:2',
            'moneda' => Moneda::class,
            'modo_moneda' => ModoMoneda::class,
            'siguiente_numero_venta' => 'integer',
        ];
    }
}
