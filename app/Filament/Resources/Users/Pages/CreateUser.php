<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

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

        if ($record instanceof User && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }
    }
}
