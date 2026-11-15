<?php

namespace App\Filament\Negocio\Resources\AjusteInventarios;

use App\Enums\MotivoAjusteInventario;
use App\Filament\Negocio\Resources\AjusteInventarios\Pages\CreateAjusteInventario;
use App\Filament\Negocio\Resources\AjusteInventarios\Pages\ListAjusteInventarios;
use App\Filament\Negocio\Resources\AjusteInventarios\Pages\ViewAjusteInventario;
use App\Inventario\AjustarInventario;
use App\Models\AjusteInventario;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class AjusteInventarioResource extends Resource
{
    protected static ?string $model = AjusteInventario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'inventario';

    protected static ?string $modelLabel = 'conteo';

    protected static ?string $pluralModelLabel = 'inventario';

    protected static ?string $navigationLabel = 'Inventario';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 8;

    public static function getEloquentQuery(): Builder
    {
        return app(AccesoSucursales::class)->filtrarAlmacen(parent::getEloquentQuery(), auth('tenant')->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipo')->label('Tipo')->formatStateUsing(fn ($state): string => $state?->etiqueta() ?? ''),
                TextColumn::make('almacen.nombre')->label('Almacén'),
                TextColumn::make('estado')->label('Estado')->formatStateUsing(fn ($state): string => $state?->etiqueta() ?? ''),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function ajustePuntual(): Action
    {
        return Action::make('ajuste')
            ->label('Ajuste puntual')
            ->color('gray')
            ->tooltip('Ajustá un solo producto por vez cuando sabés qué pasó. Si se rompieron 2 piezas del mismo producto, elegí Rotura e ingresá 2. El sistema descuenta esas unidades.')
            ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('Ajustar:Inventario'))
            ->schema([
                Select::make('almacen_id')->label('Almacén')->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())->required()->searchable()->preload()->native(false),
                Select::make('producto_variante_id')->label('Producto')->options(fn (): array => ProductoVariante::query()->orderBy('sku')->pluck('sku', 'id')->all())->required()->searchable()->preload()->native(false),
                Select::make('motivo')->label('Motivo')->options(collect(MotivoAjusteInventario::cases())->reject(fn (MotivoAjusteInventario $motivo): bool => $motivo === MotivoAjusteInventario::DiferenciaConteo)->mapWithKeys(fn (MotivoAjusteInventario $motivo): array => [$motivo->value => $motivo->etiqueta()])->all())->required()->native(false),
                TextInput::make('cantidad')->label('Cantidad')->required()->numeric()->minValue(0.0001),
                Textarea::make('observacion')->label('Qué pasó')->required(),
            ])
            ->action(function (array $data): void {
                $usuario = auth('tenant')->user();
                if (! $usuario instanceof TenantUser) {
                    throw new RuntimeException('El ajuste lo carga un usuario del negocio.');
                }
                app(AjustarInventario::class)->puntual((int) $data['almacen_id'], $data, $usuario);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAjusteInventarios::route('/'),
            'create' => CreateAjusteInventario::route('/create'),
            'view' => ViewAjusteInventario::route('/{record}'),
        ];
    }
}
