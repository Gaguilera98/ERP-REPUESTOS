<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Actions;

use App\Compras\Montos;
use App\Compras\RecibirMercaderia;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class RecibirMercaderiaAction
{
    public static function make(): Action
    {
        return Action::make('recibir')
            ->label('Recibir')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->modalHeading('Confirmar costo por unidad')
            ->modalDescription('Si el número está mal, no confirmes. Recibir no cambia el precio de venta.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Confirmar y recibir')
            ->visible(function (OrdenCompra $record): bool {
                $usuario = auth('tenant')->user();

                return $record->estado->puedeRecibir()
                    && $usuario instanceof TenantUser
                    && $usuario->can('update', $record);
            })
            ->schema(function (OrdenCompra $record): array {
                $record->loadMissing('detalles.variante.productoMaestro.unidadBase', 'detalles.unidad');
                $moneda = NegocioConfiguracion::codigoMoneda();
                $campos = [];

                foreach ($record->detalles as $detalle) {
                    $falta = bcsub((string) $detalle->cantidad_pedida, (string) $detalle->cantidad_recibida, 4);

                    if (Montos::comparar($falta, '0') !== 1) {
                        continue;
                    }

                    $campos[] = self::linea($detalle, $moneda, $falta);
                }

                $campos[] = Checkbox::make('confirmo_costos')
                    ->label('Confirmo estos costos por unidad')
                    ->accepted()
                    ->required()
                    ->validationMessages([
                        'accepted' => 'Confirmá el costo por unidad antes de recibir.',
                        'required' => 'Confirmá el costo por unidad antes de recibir.',
                    ])
                    ->extraInputAttributes([
                        'oninvalid' => "this.setCustomValidity('Confirmá el costo por unidad antes de recibir.')",
                        'onchange' => "this.setCustomValidity('')",
                    ]);

                return $campos;
            })
            ->action(function (OrdenCompra $record, array $data): void {
                $usuario = auth('tenant')->user();

                if (! $usuario instanceof TenantUser) {
                    return;
                }

                $record->loadMissing('detalles');
                $lineas = [];

                foreach ($record->detalles as $detalle) {
                    if (! $detalle instanceof OrdenCompraDetalle) {
                        continue;
                    }

                    $clave = 'cantidad_'.$detalle->id;

                    if (! array_key_exists($clave, $data)) {
                        continue;
                    }

                    $lineas[] = [
                        'detalle_id' => $detalle->id,
                        'cantidad' => $data[$clave],
                    ];
                }

                app(RecibirMercaderia::class)->recibir(
                    $record,
                    $lineas,
                    $usuario,
                    (bool) ($data['confirmo_costos'] ?? false),
                );
            })
            ->after(function (OrdenCompra $record): void {
                $record->refresh();
            })
            ->successNotificationTitle('Mercadería recibida. El costo promedio quedó actualizado.');
    }

    private static function linea(OrdenCompraDetalle $detalle, string $moneda, string $falta): Section
    {
        $nombre = $detalle->variante?->productoMaestro?->nombre ?? 'Producto';
        $sku = $detalle->variante?->sku;
        $titulo = filled($sku) ? $sku.' · '.$nombre : $nombre;
        $entera = (int) ($detalle->unidad?->decimales ?? 0) === 0;
        $aviso = $entera
            ? "this.setCustomValidity(this.validity.rangeOverflow ? 'No podés recibir más de lo que falta.' : (this.value !== '' && Number(this.value) % 1 !== 0 ? 'Esa unidad no acepta fracciones.' : 'La cantidad que llega tiene que ser mayor a cero.'))"
            : "this.setCustomValidity(this.validity.rangeOverflow ? 'No podés recibir más de lo que falta.' : 'La cantidad que llega tiene que ser mayor a cero.')";

        $cantidad = TextInput::make('cantidad_'.$detalle->id)
            ->label('Cantidad que llega')
            ->inlineLabel()
            ->suffix($detalle->unidad?->simbolo ?? 'pza')
            ->numeric()
            ->required()
            ->default($falta)
            ->minValue($entera ? 1 : 0.0001)
            ->maxValue((float) $falta)
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($detalle): void {
                    $unidad = $detalle->unidad;
                    $texto = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');

                    if (! $unidad instanceof UnidadMedida || $unidad->admiteCantidad($texto)) {
                        return;
                    }

                    $fail('Esa unidad no acepta fracciones.');
                },
            ])
            ->validationMessages([
                'min' => 'La cantidad que llega tiene que ser mayor a cero.',
                'max' => 'No podés recibir más de lo que falta.',
            ])
            ->extraInputAttributes([
                'oninvalid' => $aviso,
                'oninput' => "this.setCustomValidity('')",
            ]);

        if ($entera) {
            $cantidad->step(1);
        }

        return Section::make($titulo)
            ->description($detalle->textoCostoPorUnidad($moneda))
            ->compact()
            ->schema([
                $cantidad,
            ]);
    }
}
