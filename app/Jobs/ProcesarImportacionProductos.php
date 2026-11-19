<?php

namespace App\Jobs;

use App\Catalogo\ImportarProductos;
use App\Models\ImportacionProducto;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProcesarImportacionProductos implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30];

    public function __construct(public string $tenantId, public int $importacionId) {}

    /**
     * Execute the job.
     */
    public function handle(ImportarProductos $importador): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $tenant->run(function () use ($importador, $tenant): void {
            if (! $tenant->is_active) {
                $this->marcarFallo('El negocio está desactivado. Podés reintentar después de reactivarlo.');

                return;
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            try {
                $importador->ejecutar($this->importacionId);
            } catch (ValidationException $exception) {
                $this->marcarFallo(implode(' ', $exception->validator->errors()->all()));
            } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface) {
                $this->marcarFallo('Revisá que el usuario conserve sus permisos y acceso al almacén antes de reintentar.');
            } finally {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Tenant::query()->find($this->tenantId)?->run(fn () => $this->marcarFallo('La importación no pudo completarse. Podés reintentar desde su revisión.'));
    }

    private function marcarFallo(string $mensaje): void
    {
        ImportacionProducto::query()->whereKey($this->importacionId)->where('estado', 'en_cola')->first()?->update([
            'estado' => 'fallida', 'resultado' => ['aviso' => $mensaje],
        ]);
    }
}
