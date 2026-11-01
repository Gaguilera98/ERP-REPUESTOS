<?php

namespace Database\Factories;

use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_cliente' => TipoCliente::Empresa,
            'razon_social' => fake()->company(),
            'nombres' => null,
            'apellidos' => null,
            'tipo_precio' => TipoPrecioCliente::Menor,
        ];
    }

    public function personaNatural(): static
    {
        return $this->state(function (): array {
            $nombres = fake()->firstName();
            $apellidos = fake()->lastName();

            return [
                'tipo_cliente' => TipoCliente::PersonaNatural,
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'razon_social' => null,
            ];
        });
    }

    public function empresa(): static
    {
        return $this->state(fn (): array => [
            'tipo_cliente' => TipoCliente::Empresa,
            'razon_social' => fake()->company(),
            'nombres' => null,
            'apellidos' => null,
        ]);
    }

    public function mayor(): static
    {
        return $this->state(fn (): array => [
            'tipo_precio' => TipoPrecioCliente::Mayor,
        ]);
    }
}
