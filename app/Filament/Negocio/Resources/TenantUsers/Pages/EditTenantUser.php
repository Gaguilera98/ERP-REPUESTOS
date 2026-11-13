<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\CajaChica\Permisos;
use App\Filament\Negocio\Resources\TenantUsers\SincronizaAlcanceCajaChica;
use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\TenantUser;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantUser extends EditRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?string $assignedRole = null;

    /** @var list<int|string> */
    protected array $sucursales = [];

    /** @var list<string> */
    protected array $permisosDirectos = [];

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
        $data['sucursales'] = $record instanceof TenantUser
            ? $record->sucursales()->pluck('sucursales.id')->all()
            : [];
        $data['permisos_directos'] = $record instanceof TenantUser
            ? $record->getDirectPermissions()->pluck('name')->intersect(Permisos::nombres())->values()->all()
            : [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assignedRole = is_string($data['role'] ?? null) ? $data['role'] : null;
        $this->sucursales = is_array($data['sucursales'] ?? null) ? $data['sucursales'] : [];
        $this->permisosDirectos = is_array($data['permisos_directos'] ?? null) ? $data['permisos_directos'] : [];
        unset($data['role'], $data['sucursales'], $data['permisos_directos']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof TenantUser && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $record->syncRoles([$this->assignedRole]);
        }

        if ($record instanceof TenantUser) {
            SincronizaAlcanceCajaChica::aplicar($record, $this->sucursales, $this->permisosDirectos);
        }
    }
}
