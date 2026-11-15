<?php

namespace App\Filament\Negocio\Resources\TenantUsers;

use App\CajaChica\Permisos;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

class PermisosExtras
{
    private const GRUPOS = ['recursos' => 'Recursos', 'paginas' => 'Páginas', 'operaciones' => 'Operaciones'];

    public static function accion(): Action
    {
        return Action::make('permisosExtras')
            ->label('Permisos extras')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->authorize(fn (TenantUser $record): bool => self::autorizado($record))
            ->modalHeading(fn (TenantUser $record): string => 'Permisos extras de '.$record->name)
            ->modalDescription('Los permisos del rol aparecen marcados y bloqueados. Los permisos extras se aplican en las sucursales asignadas.')
            ->schema(fn (TenantUser $record): array => [
                Section::make('Permisos del rol')->collapsed()->schema([
                    CheckboxList::make('heredados')->hiddenLabel()->options(self::heredados($record))
                        ->disabled()->dehydrated(false)->searchable(),
                ]),
                Tabs::make('Permisos directos')->tabs(collect(self::GRUPOS)->map(
                    fn (string $etiqueta, string $grupo): Tab => Tab::make($etiqueta)->schema([
                        CheckboxList::make('directos.'.$grupo)->label($etiqueta)->searchable()->columns(2)
                            ->options(fn (): array => array_filter(self::delegables($record),
                                fn (string $nombre): bool => self::grupo($nombre) === $grupo, ARRAY_FILTER_USE_KEY)),
                    ]),
                )->values()->all()),
            ])
            ->fillForm(fn (TenantUser $record): array => [
                'heredados' => array_keys(self::heredados($record)),
                'directos' => $record->getDirectPermissions()->pluck('name')->intersect(array_keys(self::delegables($record)))
                    ->groupBy(self::grupo(...))->map(fn ($permisos): array => $permisos->values()->all())->all(),
            ])
            ->action(function (TenantUser $record, array $data): void {
                abort_unless(self::autorizado($record), 403);
                $editables = array_keys(self::delegables($record));
                $directos = $record->getDirectPermissions()->pluck('name')->all();
                $nuevos = array_values(array_unique([
                    ...array_diff($directos, $editables),
                    ...array_intersect(array_merge(...array_values($data['directos'] ?? [])), $editables),
                ]));
                $record->syncPermissions($nuevos);

                Log::info('Permisos directos del usuario actualizados', [
                    'tenant_id' => tenant()->getTenantKey(), 'actor_id' => auth('tenant')->id(), 'usuario_id' => $record->id,
                    'agregados' => array_diff($nuevos, $directos), 'retirados' => array_diff($directos, $nuevos),
                ]);
            });
    }

    private static function autorizado(TenantUser $record): bool
    {
        $actor = auth('tenant')->user();

        return $actor instanceof TenantUser && $actor->can('Administrar:AccesoUsuarios')
            && ! $actor->is($record) && ! $record->hasRole(AccesoSucursales::ADMINISTRADOR);
    }

    private static function heredados(TenantUser $record): array
    {
        return $record->getPermissionsViaRoles()->pluck('name')
            ->mapWithKeys(fn (string $nombre): array => [$nombre => self::etiqueta($nombre)])->all();
    }

    private static function delegables(TenantUser $record): array
    {
        return auth('tenant')->user()->getAllPermissions()->pluck('name')
            ->reject(fn (string $nombre): bool => $nombre === 'Administrar:AccesoUsuarios'
                || str_ends_with($nombre, ':TenantUser') || str_ends_with($nombre, ':NegocioRole'))
            ->diff(array_keys(self::heredados($record)))->sort()
            ->mapWithKeys(fn (string $nombre): array => [$nombre => self::etiqueta($nombre)])->all();
    }

    private static function grupo(string $nombre): string
    {
        if (in_array($nombre, ['View:MostradorDelNegocio', 'View:CajaDelNegocio', 'View:CajasDelDia',
            'View:CajaChica', 'View:ConfiguracionDelNegocio'], true)) {
            return 'paginas';
        }

        return preg_match('/^(View|ViewAny|Create|Update|Delete|DeleteAny|Restore|ForceDelete|ForceDeleteAny|RestoreAny|Replicate|Reorder):/', $nombre)
            ? 'recursos' : 'operaciones';
    }

    private static function etiqueta(string $nombre): string
    {
        if (isset(Permisos::etiquetas()[$nombre])) {
            return Permisos::etiquetas()[$nombre];
        }

        [$accion, $recurso] = array_pad(explode(':', $nombre, 2), 2, '');
        $accion = match ($accion) {
            'View' => 'Ver',
            'ViewAny' => 'Listar',
            'Create' => 'Crear',
            'Update' => 'Editar',
            'Delete', 'DeleteAny' => 'Eliminar',
            'Restore', 'RestoreAny' => 'Restaurar',
            'ForceDelete', 'ForceDeleteAny' => 'Eliminar definitivamente',
            'Replicate' => 'Duplicar',
            'Reorder' => 'Reordenar',
            default => $accion,
        };

        return trim($accion.' '.str($recurso)->headline()->toString());
    }
}
