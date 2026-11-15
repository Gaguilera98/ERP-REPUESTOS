<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\Filament\Negocio\Resources\TenantUsers\SincronizaSucursales;
use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\TenantUser;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantUser extends CreateRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected ?string $assignedRole = null;

    /** @var list<int|string> */
    protected array $sucursales = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assignedRole = is_string($data['role'] ?? null) ? $data['role'] : null;
        $this->sucursales = is_array($data['sucursales'] ?? null) ? $data['sucursales'] : [];
        unset($data['role'], $data['sucursales']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof TenantUser && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }

        if ($record instanceof TenantUser) {
            SincronizaSucursales::aplicar($record, $this->sucursales);
        }
    }
}
