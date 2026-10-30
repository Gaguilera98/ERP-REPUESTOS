<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Actions\CreateNegocio;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected CreateNegocio $createNegocio;

    /**
     * El alta escribe la base central y el pool. Una transacción de una sola
     * conexión no cubre las dos y, al cambiar la conexión, la deja a medias.
     */
    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    public function boot(CreateNegocio $createNegocio): void
    {
        $this->createNegocio = $createNegocio;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createNegocio->create($data);
    }
}
