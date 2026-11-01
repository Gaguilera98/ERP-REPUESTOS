<?php

namespace App\Clientes;

use App\Models\Cliente;
use App\Models\ClienteDocumento;
use Illuminate\Database\Eloquent\Builder;

class BuscarClientesMostrador
{
    public const SUGERENCIAS = 8;

    /**
     * @return array{items: list<array{id: int, nombre: string, documento: string, telefono: string}>, total: int}
     */
    public function sugerir(string $texto): array
    {
        $texto = trim($texto);

        if ($texto === '') {
            return ['items' => [], 'total' => 0];
        }

        $consulta = $this->consulta($texto);
        $total = (clone $consulta)->count();
        $items = $consulta
            ->with(['documentos.tipoDocumentoIdentidad'])
            ->orderBy('razon_social')
            ->orderBy('id')
            ->limit(self::SUGERENCIAS)
            ->get()
            ->map(fn (Cliente $cliente): array => $this->mapear($cliente))
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    public function porDocumento(string $numero): ?Cliente
    {
        $numero = trim($numero);

        if ($numero === '') {
            return null;
        }

        $documento = ClienteDocumento::query()
            ->where(function (Builder $query) use ($numero): void {
                $this->coincidirTexto($query, 'identidad', $numero);
                $query->orWhere('valores_json->numero', $numero);
            })
            ->orderBy('id')
            ->first();

        return $documento?->cliente;
    }

    public function porEmail(string $email): ?Cliente
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return Cliente::query()
            ->whereRaw('LOWER('.(new Cliente)->qualifyColumn('email').') = ?', [$email])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{id: int, nombre: string, documento: string, telefono: string}
     */
    public function mapear(Cliente $cliente): array
    {
        return [
            'id' => (int) $cliente->id,
            'nombre' => $cliente->nombre_mostrar !== '' ? $cliente->nombre_mostrar : (string) $cliente->razon_social,
            'documento' => $this->resumenDocumentos($cliente),
            'telefono' => (string) ($cliente->telefono ?? ''),
        ];
    }

    /**
     * @return Builder<Cliente>
     */
    private function consulta(string $texto): Builder
    {
        return Cliente::query()->where(function (Builder $query) use ($texto): void {
            $this->coincidirTexto($query, 'razon_social', $texto);
            $query->orWhere(function (Builder $nombres) use ($texto): void {
                $this->coincidirTexto($nombres, 'nombres', $texto);
            })->orWhere(function (Builder $apellidos) use ($texto): void {
                $this->coincidirTexto($apellidos, 'apellidos', $texto);
            })->orWhere(function (Builder $telefono) use ($texto): void {
                $this->coincidirTexto($telefono, 'telefono', $texto);
            })->orWhere(function (Builder $email) use ($texto): void {
                $this->coincidirTexto($email, 'email', $texto);
            })->orWhereHas('documentos', function (Builder $documentos) use ($texto): void {
                $this->coincidirTexto($documentos, 'identidad', $texto);
                $documentos->orWhere('valores_json->numero', $texto);
            });
        });
    }

    private function resumenDocumentos(Cliente $cliente): string
    {
        $partes = $cliente->documentos
            ->map(function (ClienteDocumento $documento): string {
                $tipo = $documento->tipoDocumentoIdentidad?->codigo
                    ?? $documento->tipoDocumentoIdentidad?->nombre
                    ?? 'doc';
                $valores = is_array($documento->valores_json) ? $documento->valores_json : [];
                $numero = (string) ($valores['numero'] ?? $documento->identidad);
                $complemento = (string) ($valores['complemento'] ?? '');

                if ($complemento !== '') {
                    $numero .= '-'.$complemento;
                }

                if ($numero === '') {
                    return '';
                }

                return strtoupper((string) $tipo).' '.$numero;
            })
            ->filter()
            ->values()
            ->all();

        return implode(' · ', $partes);
    }

    /**
     * @param  Builder<*>  $query
     */
    private function coincidirTexto(Builder $query, string $columna, string $texto): void
    {
        $columnaSql = $query->qualifyColumn($columna);

        $query->whereRaw(
            'LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE('.$columnaSql.", 'á','a'), 'é','e'), 'í','i'), 'ó','o'), 'ú','u'), 'ü','u')) LIKE ?",
            ['%'.$this->sinTildes(mb_strtolower($texto)).'%'],
        );
    }

    private function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
        ]);
    }
}
