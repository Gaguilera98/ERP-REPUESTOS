<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\Filament\Negocio\Resources\TenantUsers\SincronizaAlcanceCajaChica;
use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\TenantUser;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantUser extends CreateRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?string $assignedRole = null;

    /** @var list<int|string> */
    protected array $sucursales = [];

    /** @var list<string> */
    protected array $permisosDirectos = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assignedRole = is_string($data['role'] ?? null) ? $data['role'] : null;
        $this->sucursales = is_array($data['sucursales'] ?? null) ? $data['sucursales'] : [];
        $this->permisosDirectos = is_array($data['permisos_directos'] ?? null) ? $data['permisos_directos'] : [];
        unset($data['role'], $data['sucursales'], $data['permisos_directos']);

        return $data;
    }

    protected function afterCreate(): void
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
