<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\TenantUser;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantUser extends EditRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?string $assignedRole = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['role'] = $record instanceof TenantUser ? $record->roles->first()?->name : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assignedRole = is_string($data['role'] ?? null) ? $data['role'] : null;
        unset($data['role']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof TenantUser && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }
    }
}
