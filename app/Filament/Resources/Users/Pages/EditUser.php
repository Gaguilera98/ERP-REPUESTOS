<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
        $data['role'] = $record instanceof User ? $record->roles->first()?->name : null;

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

        if ($record instanceof User && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }
    }
}
