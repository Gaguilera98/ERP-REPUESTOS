<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\TenantUser;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantUser extends CreateRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?string $assignedRole = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assignedRole = is_string($data['role'] ?? null) ? $data['role'] : null;
        unset($data['role']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof TenantUser && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }
    }
}
