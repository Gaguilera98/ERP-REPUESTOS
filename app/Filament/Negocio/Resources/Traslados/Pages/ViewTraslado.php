<?php

namespace App\Filament\Negocio\Resources\Traslados\Pages;

use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\Traslados\TrasladoResource;
use App\Models\MovimientoInventario;
use App\Models\Traslado;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewTraslado extends ViewRecord
{
    protected static string $resource = TrasladoResource::class;

    public function getTitle(): string
    {
        return 'Traslado '.$this->getRecord()->codigo();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.negocio.resources.traslados.detalle')
                ->viewData(function (Traslado $record): array {
                    $record->loadMissing(['origen', 'destino', 'usuario', 'detalles.variante.productoMaestro.unidadBase']);

                    return [
                        'traslado' => $record,
                        'movimientos' => MovimientoInventario::query()
                            ->where('referencia_tipo', 'traslado')
                            ->where('referencia_id', $record->id)
                            ->whereIn('tipo_movimiento', [TipoMovimientoInventario::TrasladoEntrada, TipoMovimientoInventario::TrasladoSalida])
                            ->with(['almacen', 'variante'])->orderBy('id')->get(),
                    ];
                })->columnSpanFull(),
        ]);
    }
}
