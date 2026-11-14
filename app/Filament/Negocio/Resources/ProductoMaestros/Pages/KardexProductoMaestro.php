<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Compras\Montos;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\ProductoMaestro;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
        $puedeVerCompras = Filament::auth()->user()?->can('View:OrdenCompra') ?? false;
        $moneda = $puedeVerCompras ? NegocioConfiguracion::codigoMoneda() : '';
        $unidad = $producto->unidadBase?->simbolo;

        return $table
            ->query(MovimientoInventario::query()
                ->whereHas('variante', fn (Builder $variantes): Builder => $variantes->where('producto_maestro_id', $producto->getKey()))
                ->with($puedeVerCompras ? ['variante', 'almacen', 'ordenCompraReferenciada'] : ['variante', 'almacen']))
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
                TextColumn::make('stock_posterior')->label('Stock tras el movimiento')->alignEnd()
                    ->headerTooltip('Existencia total del SKU en ese almacén justo después del movimiento.')
                    ->formatStateUsing(fn (string $state): string => Montos::sinCeros($state)),
                TextColumn::make('costo_unitario_entrada')->label('Costo unitario de entrada')->alignEnd()
                    ->visible($puedeVerCompras)
                    ->state(fn (MovimientoInventario $record): ?string => self::esEntradaDeCompra($record) ? $record->costo_unitario : null)
                    ->formatStateUsing(function (string $state) use ($moneda, $unidad): string {
                        $redondeado = Montos::dos($state);
                        $costo = Montos::comparar($state, $redondeado) === 0 ? $redondeado : Montos::sinCeros($state);

                        return $moneda.' '.$costo.(filled($unidad) ? ' / '.$unidad : '');
                    })
                    ->placeholder('—'),
                TextColumn::make('costo_total_entrada')->label('Costo de la entrada')->alignEnd()
                    ->headerTooltip('Cantidad recibida por el costo unitario registrado en este movimiento.')
                    ->visible($puedeVerCompras)
                    ->state(fn (MovimientoInventario $record): ?string => self::esEntradaDeCompra($record) && $record->costo_unitario !== null
                        ? $moneda.' '.Montos::multiplicar($record->cantidad, $record->costo_unitario, 2)
                        : null)
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('verCompra')->label('Ver compra')->icon(Heroicon::OutlinedEye)
                    ->visible(fn (MovimientoInventario $record): bool => $puedeVerCompras
                        && self::esEntradaDeCompra($record)
                        && $record->referencia_tipo === MovimientoInventario::REFERENCIA_ORDEN_COMPRA
                        && $record->ordenCompraReferenciada instanceof OrdenCompra
                        && OrdenCompraResource::canView($record->ordenCompraReferenciada))
                    ->url(fn (MovimientoInventario $record): string => OrdenCompraResource::getUrl('view', ['record' => $record->ordenCompraReferenciada])),
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

    private static function esEntradaDeCompra(MovimientoInventario $movimiento): bool
    {
        return in_array($movimiento->tipo_movimiento, [
            TipoMovimientoInventario::EntradaCompra,
            TipoMovimientoInventario::EntradaFlashEmergencia,
        ], true);
    }
}
