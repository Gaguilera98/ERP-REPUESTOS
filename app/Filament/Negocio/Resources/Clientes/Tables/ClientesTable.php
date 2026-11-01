<?php

namespace App\Filament\Negocio\Resources\Clientes\Tables;

use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->modifyQueryUsing(fn ($query) => $query->with(['documentos.tipoDocumentoIdentidad']))
            ->columns([
                TextColumn::make('razon_social')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo_cliente')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoCliente $state): string => $state->etiqueta()),
                TextColumn::make('tipo_precio')
                    ->label('Precio')
                    ->badge()
                    ->formatStateUsing(fn (TipoPrecioCliente $state): string => $state->etiqueta()),
                TextColumn::make('documentos_resumen')
                    ->label('Documentos')
                    ->state(function ($record): string {
                        $partes = $record->documentos
                            ->map(function ($documento): string {
                                $tipo = $documento->tipoDocumentoIdentidad?->codigo
                                    ?? $documento->tipoDocumentoIdentidad?->nombre
                                    ?? 'doc';
                                $valores = is_array($documento->valores_json) ? $documento->valores_json : [];
                                $numero = (string) ($valores['numero'] ?? $documento->identidad);
                                $complemento = (string) ($valores['complemento'] ?? '');
                                if ($complemento !== '') {
                                    $numero .= '-'.$complemento;
                                }

                                return strtoupper((string) $tipo).' '.$numero;
                            })
                            ->filter()
                            ->values()
                            ->all();

                        return $partes === [] ? '—' : implode(' · ', $partes);
                    })
                    ->wrap(),
                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—'),
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
            ->emptyStateHeading('No hay clientes')
            ->emptyStateDescription('Cargá el taller o el particular antes de venderle a cuenta o con factura.')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup));
    }
}
