<?php

namespace App\Archivos;

use App\Enums\TipoArchivoNegocio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ArchivosNegocio
{
    public function directorio(TipoArchivoNegocio $tipo): string
    {
        return $this->carpetaDelTenant($this->tenantId()).'/'.$tipo->value;
    }

    public function disco(TipoArchivoNegocio $tipo): ?string
    {
        return $tipo->esPublico() ? 'public' : null;
    }

    public function guardar(UploadedFile $archivo, TipoArchivoNegocio $tipo): string
    {
        $disco = $this->disco($tipo);
        $directorio = $this->directorio($tipo);

        $ruta = $disco === null
            ? $archivo->store($directorio)
            : $archivo->store($directorio, $disco);

        if (! is_string($ruta) || $ruta === '') {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }

        return $ruta;
    }

    public function urlTemporal(?string $ruta, int $minutos = 5): ?string
    {
        if (! $this->perteneceAlTenantAbierto($ruta)) {
            return null;
        }

        return Storage::temporaryUrl($ruta, now()->addMinutes($minutos));
    }

    public function borrarDelTenant(string $tenantId): void
    {
        $carpeta = $this->carpetaDelTenant($tenantId);

        if (! Storage::disk('public')->deleteDirectory($carpeta) || ! Storage::deleteDirectory($carpeta)) {
            throw new RuntimeException('No se pudieron borrar los archivos del negocio.');
        }
    }

    private function carpetaDelTenant(string $tenantId): string
    {
        return 'tenants/'.$tenantId;
    }

    private function perteneceAlTenantAbierto(?string $ruta): bool
    {
        if (! is_string($ruta) || $ruta === '' || str_contains($ruta, '..')) {
            return false;
        }

        return str_starts_with(str_replace('\\', '/', $ruta), $this->carpetaDelTenant($this->tenantId()).'/');
    }

    private function tenantId(): string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Los archivos del negocio se usan con un negocio abierto.');
        }

        return (string) tenant()->getTenantKey();
    }
}
