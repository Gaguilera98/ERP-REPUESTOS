<?php

namespace App\Filament\Negocio\Resources\AjusteInventarios\Pages;

use App\Compras\Montos;
use App\Enums\EstadoAjusteInventario;
use App\Enums\TipoAjusteInventario;
use App\Filament\Negocio\Resources\AjusteInventarios\AjusteInventarioResource;
use App\Inventario\AjustarInventario;
use App\Models\AjusteDetalle;
use App\Models\AjusteInventario;
use App\Models\TenantUser;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ViewAjusteInventario extends ViewRecord
{
    protected static string $resource = AjusteInventarioResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.negocio.resources.ajuste-inventarios.resumen')
                ->viewData(fn (AjusteInventario $record): array => $this->resumen($record))
                ->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cerrar')
                ->label('Cerrar y aplicar conteo')
                ->visible(fn (): bool => $this->getRecord() instanceof AjusteInventario
                    && $this->getRecord()->tipo === TipoAjusteInventario::Conteo
                    && $this->getRecord()->estado === EstadoAjusteInventario::EnCurso
                    && (bool) auth('tenant')->user()?->can('Cerrar:Conteo'))
                ->requiresConfirmation()
                ->modalDescription('Se aplicarán las diferencias al stock y se desbloqueará el almacén.')
                ->action(function (): void {
                    $usuario = auth('tenant')->user();
                    $record = $this->getRecord();
                    if (! $usuario instanceof TenantUser || ! $record instanceof AjusteInventario) {
                        throw new RuntimeException('El conteo lo cierra un usuario del negocio.');
                    }
                    try {
                        app(AjustarInventario::class)->cerrarConteo($record, $usuario);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title($exception->validator->errors()->first())
                            ->send();

                        return;
                    }

                    $record->refresh();
                    Notification::make()->success()->title('Conteo cerrado')->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    private function resumen(AjusteInventario $record): array
    {
        $record->loadMissing(['almacen', 'usuario', 'supervisor', 'detalles.variante.productoMaestro.unidadBase']);

        $filas = $record->detalles->map(function (AjusteDetalle $detalle): array {
            $diferencia = bcsub($detalle->cantidad_fisica, $detalle->cantidad_sistema, 4);

            return [
                'nombre' => $detalle->variante?->productoMaestro?->nombre,
                'sku' => $detalle->variante?->sku,
                'unidad' => $detalle->variante?->productoMaestro?->unidadBase?->simbolo,
                'sistema' => Montos::sinCeros($detalle->cantidad_sistema),
                'fisica' => Montos::sinCeros($detalle->cantidad_fisica),
                'diferencia' => Montos::sinCeros($diferencia),
                'sentido' => Montos::comparar($diferencia, '0'),
            ];
        });

        return [
            'ajuste' => $record,
            'abierto' => $record->estado === EstadoAjusteInventario::EnCurso,
            'esConteo' => $record->tipo === TipoAjusteInventario::Conteo,
            'filas' => $filas,
            'diferencias' => $filas->where('sentido', '!=', 0)->count(),
        ];
    }
}
