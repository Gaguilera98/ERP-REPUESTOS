<?php

namespace App\Clientes;

use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Models\Cliente;
use App\Models\TipoDocumentoIdentidad;
use Illuminate\Validation\ValidationException;

class RegistrarClienteRapido
{
    public function __construct(private BuscarClientesMostrador $buscador) {}

    /**
     * @param  array{
     *     tipo?: string,
     *     nombres?: string,
     *     apellidos?: string,
     *     razon_social?: string,
     *     documento?: string,
     *     telefono?: string,
     *     email?: string
     * }  $data
     * @return array{cliente: Cliente, existia: bool}
     */
    public function handle(array $data): array
    {
        $tipo = TipoCliente::tryFrom((string) ($data['tipo'] ?? TipoCliente::PersonaNatural->value))
            ?? TipoCliente::PersonaNatural;
        $nombres = trim((string) ($data['nombres'] ?? ''));
        $apellidos = trim((string) ($data['apellidos'] ?? ''));
        $razonSocial = trim((string) ($data['razon_social'] ?? ''));
        $documento = trim((string) ($data['documento'] ?? ''));
        $telefono = trim((string) ($data['telefono'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($tipo === TipoCliente::Empresa) {
            if ($razonSocial === '') {
                throw ValidationException::withMessages([
                    'clienteRazonSocial' => 'La razón social es obligatoria.',
                ]);
            }
        } elseif ($nombres === '' || $apellidos === '') {
            throw ValidationException::withMessages([
                'clienteNombres' => 'El nombre y los apellidos son obligatorios.',
                'clienteApellidos' => 'El nombre y los apellidos son obligatorios.',
            ]);
        }

        if ($documento === '') {
            throw ValidationException::withMessages([
                'clienteDocumento' => $tipo === TipoCliente::Empresa
                    ? 'El NIT es obligatorio.'
                    : 'El CI o NIT es obligatorio.',
            ]);
        }

        if ($telefono === '') {
            throw ValidationException::withMessages([
                'clienteTelefono' => 'El teléfono es obligatorio.',
            ]);
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'clienteEmail' => 'El correo no es válido.',
            ]);
        }

        $existente = $this->existente($documento, $email);

        if ($existente instanceof Cliente) {
            return ['cliente' => $existente, 'existia' => true];
        }

        $cliente = Cliente::query()->create([
            'tipo_cliente' => $tipo,
            'nombres' => $tipo === TipoCliente::PersonaNatural ? $nombres : null,
            'apellidos' => $tipo === TipoCliente::PersonaNatural ? $apellidos : null,
            'razon_social' => $tipo === TipoCliente::Empresa ? $razonSocial : null,
            'telefono' => $telefono !== '' ? $telefono : null,
            'email' => $email !== '' ? $email : null,
            'tipo_precio' => TipoPrecioCliente::Menor,
        ]);

        $this->adjuntarDocumento($cliente, $documento, $tipo);

        return ['cliente' => $cliente->fresh(['documentos.tipoDocumentoIdentidad']) ?? $cliente, 'existia' => false];
    }

    private function existente(string $documento, string $email): ?Cliente
    {
        if ($documento !== '') {
            $porDocumento = $this->buscador->porDocumento($documento);

            if ($porDocumento instanceof Cliente) {
                return $porDocumento;
            }
        }

        if ($email !== '') {
            return $this->buscador->porEmail($email);
        }

        return null;
    }

    private function adjuntarDocumento(Cliente $cliente, string $documento, TipoCliente $tipo): void
    {
        if ($documento === '') {
            return;
        }

        $tipoDocumento = TipoDocumentoIdentidad::query()
            ->where('codigo', 'nit')
            ->where('is_active', true)
            ->first();

        if (! $tipoDocumento instanceof TipoDocumentoIdentidad) {
            return;
        }

        $cliente->documentos()->create([
            'tipo_documento_identidad_id' => $tipoDocumento->id,
            'valores_json' => ['numero' => $documento],
        ]);
    }
}
