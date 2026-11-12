<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Compras\Montos;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoMaestro;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KardexProductoMaestro extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProductoMaestroResource::class;

    protected string $view = 'filament.negocio.resources.producto-maestros.pages.kardex-producto-maestro';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::canAccess(['record' => $this->record]), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $producto = $parameters['record'] ?? null;

        return $producto instanceof ProductoMaestro && static::getResource()::canView($producto);
    }

    public function getTitle(): string
    {
        return 'Kardex · '.$this->getRecord()->nombre;
    }

    public function getBreadcrumb(): string
    {
        return 'Kardex';
    }

    public function table(Table $table): Table
    {
        $producto = $this->getRecord();

        return $table
            ->query(MovimientoInventario::query()
                ->whereHas('variante', fn (Builder $variantes): Builder => $variantes->where('producto_maestro_id', $producto->getKey()))
                ->with(['variante', 'almacen']))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('variante.sku')->label('SKU'),
                TextColumn::make('almacen.nombre')->label('Almacén'),
                TextColumn::make('tipo_movimiento')->label('Movimiento')->badge()
                    ->formatStateUsing(fn (TipoMovimientoInventario $state): string => $state->etiqueta())
                    ->color(fn (MovimientoInventario $record): string => match (bccomp($record->stock_posterior, $record->stock_previo, 4)) {
                        -1 => 'warning',
                        1 => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('variacion')->label('Cantidad')->alignEnd()
                    ->state(fn (MovimientoInventario $record): string => Montos::sinCeros(bcsub($record->stock_posterior, $record->stock_previo, 4)))
                    ->formatStateUsing(fn (string $state): string => bccomp($state, '0', 4) > 0 ? '+'.$state : $state),
                TextColumn::make('stock_posterior')->label('Stock resultante')->alignEnd()
                    ->formatStateUsing(fn (string $state): string => Montos::sinCeros($state)),
            ])
            ->filters([
                SelectFilter::make('almacen_id')->label('Almacén')->searchable()
                    ->options(fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all()),
                SelectFilter::make('tipo_movimiento')->label('Movimiento')->multiple()->options(
                    collect(TipoMovimientoInventario::cases())->mapWithKeys(fn (TipoMovimientoInventario $tipo): array => [$tipo->value => $tipo->etiqueta()])->all(),
                ),
                Filter::make('dia')->schema([DatePicker::make('fecha')->label('Día del movimiento')->native(false)->displayFormat('d/m/Y')])
                    ->query(function (Builder $query, array $data): Builder {
                        $fecha = $data['fecha'] ?? null;
                        if (blank($fecha)) {
                            return $query;
                        }
                        if (! validator(['fecha' => $fecha], ['fecha' => ['date_format:Y-m-d,Y-m-d H:i:s']])->passes()) {
                            return $query->whereKey(0);
                        }

                        return $query->whereDate('created_at', substr($fecha, 0, 10));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->emptyStateHeading('No hay movimientos para esta consulta');
    }
}
