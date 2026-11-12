<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Catalogo\VariantesProducto;
use App\Compras\Montos;
use App\Filament\Negocio\Resources\ProductoMaestros\Concerns\GuardaVariantesDelProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\InventarioStock;
use App\Models\ProductoMaestro;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

class EditProductoMaestro extends EditRecord
{
    use GuardaVariantesDelProducto;

    protected static string $resource = ProductoMaestroResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kardex')->label('Ver kardex')->icon(Heroicon::OutlinedClock)->color('gray')
                ->authorize(fn (): bool => ProductoMaestroResource::canView($this->getRecord()))
                ->url(fn (): string => $this->getResourceUrl('kardex')),
            DeleteAction::make(),
        ];
    }

    #[Unexposed]
    public function getFormContentComponent(): Component
    {
        /** @var ProductoMaestro $producto */
        $producto = $this->getRecord();
        $unidad = $producto->unidadBase?->simbolo;
        $formatearCantidad = fn (string $state): string => trim(Montos::sinCeros($state).' '.$unidad);

        return Group::make([
            Section::make('Stock por almacén')->schema([
                RepeatableEntry::make('stock_por_almacen')
                    ->hiddenLabel()
                    ->placeholder('No hay existencias registradas.')
                    ->state(fn (): Collection => $producto->loadMissing('stocks.almacen', 'stocks.variante')->stocks
                        ->sortBy(['almacen.nombre', 'variante.sku']))
                    ->table([
                        TableColumn::make('Almacén'),
                        TableColumn::make('SKU'),
                        TableColumn::make('Stock físico'),
                        TableColumn::make('Reservado'),
                        TableColumn::make('Disponible'),
                    ])
                    ->schema([
                        TextEntry::make('almacen.nombre'),
                        TextEntry::make('variante.sku'),
                        TextEntry::make('cantidad_actual')->formatStateUsing($formatearCantidad),
                        TextEntry::make('cantidad_reservada')->formatStateUsing($formatearCantidad),
                        TextEntry::make('cantidad_disponible')
                            ->state(fn (InventarioStock $record): string => bcsub($record->cantidad_actual, $record->cantidad_reservada, 4))
                            ->formatStateUsing($formatearCantidad),
                    ]),
            ]),
            parent::getFormContentComponent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $producto = $this->getRecord();

        if (! $producto instanceof ProductoMaestro) {
            return $data;
        }

        return [
            ...$data,
            ...app(VariantesProducto::class)->estadoDesde($producto),
        ];
    }
}
