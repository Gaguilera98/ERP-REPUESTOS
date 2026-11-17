<?php

namespace App\Filament\Negocio\Resources\Ventas;

use App\Enums\CondicionVenta;
use App\Enums\EstadoVenta;
use App\Filament\Negocio\Resources\Ventas\Pages\ListVentas;
use App\Livewire\Negocio\Caja\AccionVerDetalleVenta;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class VentaResource extends Resource
{
    protected static ?string $model = Venta::class;

    protected static ?string $slug = 'ventas';

    protected static ?string $modelLabel = 'venta';

    protected static ?string $pluralModelLabel = 'ventas';

    protected static ?string $navigationLabel = 'Ventas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $usuario = auth('tenant')->user();
        abort_unless($usuario instanceof TenantUser, 403);

        return app(AccesoSucursales::class)->filtrarSucursal(parent::getEloquentQuery(), $usuario)
            ->when(! $usuario->can('viewOtherSales', Venta::class), fn (Builder $query): Builder => $query
                ->where('user_id', $usuario->getKey()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ultimaCorreccion')
                ->withSum('devoluciones as total_devuelto', 'total'))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Buscar por código o cliente')
            ->columns([
                TextColumn::make('codigo')->label('Venta')->searchable(),
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('sucursal.nombre')->label('Sucursal'),
                TextColumn::make('cliente.nombre_mostrar')->label('Cliente')->placeholder('Consumidor final')
                    ->searchable(['nombres', 'apellidos', 'razon_social']),
                TextColumn::make('usuario.name')->label('Vendedor'),
                TextColumn::make('condicion')->label('Condición')
                    ->formatStateUsing(fn (CondicionVenta $state): string => $state->etiqueta()),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoVenta $state): string => $state->etiqueta())
                    ->color(fn (EstadoVenta $state): array => AccionVerDetalleVenta::colorEstado($state)),
                TextColumn::make('total')->label('Total')
                    ->state(fn (Venta $record): string => $record->totalVigente())
                    ->money(NegocioConfiguracion::codigoMoneda()),
            ])
            ->filters([
                Filter::make('fecha')->label('Fecha de venta')
                    ->columnSpan(['default' => 1, 'sm' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        DatePicker::make('desde')->label('Desde')->default(fn (): string => now()->toDateString())
                            ->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('hasta')->label('Hasta')->default(fn (): string => now()->toDateString())
                            ->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $fechas = [
                            'desde' => ($data['desde'] ?? '') ?: null,
                            'hasta' => ($data['hasta'] ?? '') ?: null,
                        ];

                        if (validator($fechas, [
                            'desde' => ['nullable', 'date_format:Y-m-d,Y-m-d H:i:s'],
                            'hasta' => ['nullable', 'date_format:Y-m-d,Y-m-d H:i:s'],
                        ])->fails()) {
                            return $query->whereKey(0);
                        }

                        return $query
                            ->when($fechas['desde'], fn (Builder $query, string $desde): Builder => $query
                                ->whereDate('created_at', '>=', substr($desde, 0, 10)))
                            ->when($fechas['hasta'], fn (Builder $query, string $hasta): Builder => $query
                                ->whereDate('created_at', '<=', substr($hasta, 0, 10)));
                    }),
                SelectFilter::make('sucursal_id')->label('Sucursal')->placeholder('Todas las autorizadas')
                    ->options(fn (): array => app(AccesoSucursales::class)->consulta(auth('tenant')->user())
                        ->orderBy('nombre')->pluck('nombre', 'id')->all()),
                SelectFilter::make('user_id')->label('Vendedor')
                    ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('viewOtherSales', Venta::class))
                    ->options(fn (): array => TenantUser::query()->whereIn('id', static::getEloquentQuery()->select('user_id'))
                        ->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('estado')->label('Estado')
                    ->options(collect(EstadoVenta::cases())->mapWithKeys(
                        fn (EstadoVenta $estado): array => [$estado->value => $estado->etiqueta()],
                    )->all()),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 5])
            ->deferFilters(false)
            ->recordActions([app(AccionVerDetalleVenta::class)->crear(habilidad: 'viewInHistory')])
            ->emptyStateHeading('No hay ventas para esta consulta');
    }

    public static function getPages(): array
    {
        return ['index' => ListVentas::route('/')];
    }
}
