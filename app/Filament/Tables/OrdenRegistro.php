<?php

namespace App\Filament\Tables;

use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdenRegistro
{
    public static function aplicar(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->pushFilters([static::filtro()]);
    }

    public static function filtro(): SelectFilter
    {
        return SelectFilter::make('orden')
            ->label('Orden')
            ->options([
                'desc' => 'Últimos registrados',
                'asc' => 'Primeros registrados',
            ])
            ->default('desc')
            ->selectablePlaceholder(false)
            ->native(false)
            ->indicateUsing(function (array $state): array {
                if (($state['value'] ?? null) !== 'asc') {
                    return [];
                }

                return [Indicator::make('Primeros registrados')];
            })
            ->query(fn (Builder $query): Builder => $query)
            ->baseQuery(function (Builder $query, array $data, mixed $livewire): void {
                if (is_object($livewire) && method_exists($livewire, 'getTableSortColumn') && filled($livewire->getTableSortColumn())) {
                    return;
                }

                $direccion = ($data['value'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

                // La fecha sola no alcanza: varias filas quedan en el mismo segundo
                // y Filament desempataba siempre con el id descendente.
                $query->reorder($query->qualifyColumn('created_at'), $direccion)
                    ->orderBy($query->getModel()->getQualifiedKeyName(), $direccion);
            });
    }
}
