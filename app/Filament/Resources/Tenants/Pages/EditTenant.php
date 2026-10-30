<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Rules\NegocioDomainRule;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected ?string $domain = null;

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
        $data['domain'] = $record instanceof Tenant ? $record->domains()->value('domain') : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->domain = is_string($data['domain'] ?? null)
            ? NegocioDomainRule::normalize($data['domain'])
            : null;

        unset($data['domain'], $data['db_pool']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Tenant || ! is_string($this->domain) || $this->domain === '') {
            return;
        }

        $existing = $record->domains()->first();

        if ($existing) {
            $existing->update(['domain' => $this->domain]);

            return;
        }

        $record->domains()->create(['domain' => $this->domain]);
    }
}
