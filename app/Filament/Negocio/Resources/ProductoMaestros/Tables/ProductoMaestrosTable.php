<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Tables;

use App\Filament\Tables\OrdenRegistro;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoMaestro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductoMaestrosTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                ImageColumn::make('imagenes')
                    ->label('Foto')
                    ->disk(fn (): string => config('filesystems.default') === 's3' ? 's3' : 'public')
                    ->circular()
                    ->stacked()
                    ->limit(1)
                    ->defaultImageUrl(url('/images/placeholder-part.svg')),

                TextColumn::make('nombre')
                    ->label('Producto')
                    ->weight('bold')
                    ->searchable(query: function ($query, string $search) {
                        $query->where('nombre', 'like', "%{$search}%")
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
                    ->label('SKUs')
                    ->badge()
                    ->color('info')
                    ->limitList(2)
                    ->expandableLimitedList(),

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
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
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
