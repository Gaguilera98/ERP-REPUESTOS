<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Pages;

use App\Filament\Negocio\Resources\TenantUsers\PermisosExtras;
use App\Filament\Negocio\Resources\TenantUsers\SincronizaSucursales;
use App\Filament\Negocio\Resources\TenantUsers\TenantUserResource;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditTenantUser extends EditRecord
{
    protected static string $resource = TenantUserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected ?string $assignedRole = null;

    /** @var list<int|string> */
    protected array $sucursales = [];

    protected function getHeaderActions(): array
    {
        return [
            PermisosExtras::accion(),
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
        $data['role'] = $record->hasRole(AccesoSucursales::ADMINISTRADOR)
            ? AccesoSucursales::ADMINISTRADOR : $record->roles->first()?->name;
        $data['sucursales'] = $record instanceof TenantUser
            ? $record->sucursales()->pluck('sucursales.id')->all()
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
        unset($data['role'], $data['sucursales']);

        if ($this->assignedRole !== AccesoSucursales::ADMINISTRADOR || ! ($data['is_active'] ?? false)) {
            NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();
            $registro = $this->getRecord()->refresh();
            if ($registro->hasRole(AccesoSucursales::ADMINISTRADOR) && ! TenantUser::role(AccesoSucursales::ADMINISTRADOR)
                ->where('is_active', true)->whereKeyNot($registro->getKey())->exists()) {
                throw ValidationException::withMessages(['data.role' => 'El negocio debe conservar al menos un administrador activo.']);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof TenantUser && is_string($this->assignedRole) && $this->assignedRole !== '') {
            $anteriores = $record->getRoleNames()->all();
            $record->syncRoles([$this->assignedRole]);
            if ($anteriores !== [$this->assignedRole]) {
                Log::info('Rol del usuario actualizado', [
                    'tenant_id' => tenant()->getTenantKey(), 'actor_id' => auth('tenant')->id(), 'usuario_id' => $record->id,
                    'roles_anteriores' => $anteriores, 'rol' => $this->assignedRole,
                ]);
            }
        }

        if ($record instanceof TenantUser && $this->assignedRole !== AccesoSucursales::ADMINISTRADOR) {
            SincronizaSucursales::aplicar($record, $this->sucursales);
        }
    }
}
