<?php

namespace App\Models;

use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'tenant_id',
    'tipo_cliente',
    'nombres',
    'apellidos',
    'razon_social',
    'tipo_precio',
    'telefono',
    'email',
    'direccion',
])]
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'clientes';

    protected $attributes = [
        'tipo_cliente' => 'persona_natural',
        'tipo_precio' => 'menor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_cliente' => TipoCliente::class,
            'tipo_precio' => TipoPrecioCliente::class,
        ];
    }

    /**
     * @return HasMany<ClienteDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(ClienteDocumento::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function nombreMostrar(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->tipo_cliente === TipoCliente::Empresa) {
                return (string) ($this->razon_social ?? '');
            }

            return trim(implode(' ', array_filter([
                $this->nombres,
                $this->apellidos,
            ], fn (?string $parte): bool => filled($parte))));
        });
    }

    protected static function booted(): void
    {
        static::saving(function (Cliente $cliente): void {
            foreach (['nombres', 'apellidos', 'razon_social', 'telefono', 'email'] as $campo) {
                $valor = $cliente->{$campo};
                $cliente->{$campo} = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
            }

            $direccion = $cliente->direccion;
            $cliente->direccion = is_string($direccion) && trim($direccion) !== '' ? trim($direccion) : null;

            $tipo = $cliente->tipo_cliente instanceof TipoCliente
                ? $cliente->tipo_cliente
                : TipoCliente::tryFrom((string) $cliente->tipo_cliente);

            if ($tipo === TipoCliente::Empresa) {
                $cliente->nombres = null;
                $cliente->apellidos = null;

                if (! filled($cliente->razon_social)) {
                    throw ValidationException::withMessages([
                        'razon_social' => 'La razón social es obligatoria para empresa.',
                    ]);
                }

                return;
            }

            if ($tipo === TipoCliente::PersonaNatural) {
                if (! filled($cliente->nombres) || ! filled($cliente->apellidos)) {
                    throw ValidationException::withMessages([
                        'nombres' => 'El nombre y los apellidos son obligatorios para persona natural.',
                        'apellidos' => 'El nombre y los apellidos son obligatorios para persona natural.',
                    ]);
                }

                $cliente->razon_social = trim($cliente->nombres.' '.$cliente->apellidos);
            }
        });
    }
}
