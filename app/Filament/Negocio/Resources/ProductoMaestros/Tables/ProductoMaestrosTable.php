<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Tables;

use App\Compras\Montos;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Filament\Tables\OrdenRegistro;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoMaestro;
use App\Models\SucursalAlmacen;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductoMaestrosTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->modifyQueryUsing(function (Builder $query, HasTable $livewire): Builder {
                $acceso = app(AccesoSucursales::class);
                $usuario = auth('tenant')->user();
                $query->with('unidadBase:id,simbolo');
                if (! $acceso->puedeVerStock($usuario)) {
                    return $query;
                }

                $id = $livewire->getTableFilterState('sucursal_stock')['value'] ?? null;
                $stock = fn (Builder $stocks): Builder => $acceso->filtrarAlmacen($stocks, $usuario)
                    ->when(filled($id), fn (Builder $stocks) => $stocks->whereIn('almacen_id', SucursalAlmacen::query()
                        ->where('sucursal_id', $id)
                        ->whereIn('sucursal_id', $acceso->consulta($usuario)->select('sucursales.id'))
                        ->select('almacen_id')));

                return $query->withSum(['stocks as stock_fisico' => $stock], 'cantidad_actual')
                    ->withSum(['stocks as stock_reservado' => $stock], 'cantidad_reservada');
            })
            ->columns([
                ImageColumn::make('imagenes')
                    ->label('Foto')
                    ->disk('public')
                    ->circular()
                    ->stacked()
                    ->limit(1)
                    ->defaultImageUrl(url('/images/placeholder-part.svg')),

                TextColumn::make('codigo_repuesto')
                    ->label('Código del producto')
                    ->state(fn (ProductoMaestro $record): ?string => filled($record->codigo_repuesto)
                        ? $record->codigo_repuesto
                        : ($record->variantes->count() === 1 ? $record->variantes->first()->sku : null))
                    ->weight('bold')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Producto')
                    ->weight('bold')
                    ->searchable(query: function ($query, string $search) {
                        $query->where('nombre', 'like', "%{$search}%")
                            ->orWhere('codigo_repuesto', 'like', "%{$search}%")
                            ->orWhereHas('variantes', function ($vQuery) use ($search) {
                                $vQuery->where('sku', 'like', "%{$search}%")
                                    ->orWhere('codigo_barras', 'like', "%{$search}%");
                            });
                    })
                    ->sortable()
                    ->description(fn (ProductoMaestro $record): ?string => $record->categoria?->nombre),

                TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('variantes.sku')
                    ->label('SKU internos')
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('variantes.condicion.nombre')
                    ->label('Condición')
                    ->badge()
                    ->color('gray')
                    ->distinctList()
                    ->placeholder('Sin asignar')
                    ->toggleable(),

                TextColumn::make('precio_resumen')
                    ->label('Precio Venta')
                    ->state(function (ProductoMaestro $record): string {
                        $precios = $record->variantes->pluck('precio_venta')->filter();

                        if ($precios->isEmpty()) {
                            return '—';
                        }

                        $min = (float) $precios->min();
                        $max = (float) $precios->max();
                        $moneda = NegocioConfiguracion::codigoMoneda();

                        if ($min === $max) {
                            return "{$moneda} ".number_format($min, 2);
                        }

                        return "{$moneda} ".number_format($min, 2).' - '.number_format($max, 2);
                    })
                    ->badge()
                    ->color('success'),

                TextColumn::make('stock_fisico')
                    ->visible(fn (): bool => app(AccesoSucursales::class)->puedeVerStock(auth('tenant')->user()))
                    ->label('Stock total')
                    ->alignEnd()
                    ->state(fn (ProductoMaestro $record): string => Montos::sinCeros($record->stock_fisico ?? 0))
                    ->formatStateUsing(fn (string $state, ProductoMaestro $record): string => trim($state.' '.$record->unidadBase?->simbolo))
                    ->description(function (ProductoMaestro $record): ?string {
                        $reservado = (string) ($record->stock_reservado ?? '0');
                        if (bccomp($reservado, '0', 4) === 0) {
                            return null;
                        }

                        $disponible = bcsub((string) $record->stock_fisico, $reservado, 4);

                        return Montos::sinCeros($disponible).' disponibles';
                    }),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('variantes_count')
                    ->counts('variantes')
                    ->label('Variantes')
                    ->badge()
                    ->color(fn (int $state): string => $state > 1 ? 'warning' : 'primary')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('sucursal_stock')
                    ->label('Stock por sucursal')
                    ->visible(fn (): bool => app(AccesoSucursales::class)->puedeVerStock(auth('tenant')->user())
                        && app(AccesoSucursales::class)->disponibles(auth('tenant')->user())->count() > 1)
                    ->placeholder('Todas las autorizadas')
                    ->default(fn (): ?int => app(AccesoSucursales::class)->activa(auth('tenant')->user()))
                    ->options(fn (): array => app(AccesoSucursales::class)->disponibles(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('kardex')->label('Ver kardex')->icon(Heroicon::OutlinedClock)
                    ->authorize(fn (ProductoMaestro $record): bool => ProductoMaestroResource::canView($record)
                        && app(AccesoSucursales::class)->puedeVerStock(auth('tenant')->user()))
                    ->url(fn (ProductoMaestro $record): string => ProductoMaestroResource::getUrl('kardex', ['record' => $record])),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay productos en el catálogo')
            ->emptyStateDescription('Registra el producto con su código y precio. Las variantes se agregan solo si el rubro las distingue.')
            ->emptyStateIcon(Heroicon::OutlinedCube));
    }
}
