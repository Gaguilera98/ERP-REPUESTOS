<?php

namespace App\Livewire\Negocio\Caja;

use App\Enums\MetodoPago;
use App\Enums\TipoComprobante;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\RegistrarDevolucion;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class AccionDevolverVenta
{
    public function __construct(private RegistrarDevolucion $devoluciones) {}

    public function para(Venta $venta): Action
    {
        return Action::make('devolverVenta')->label('Registrar devolución')->color('warning')->icon('heroicon-o-arrow-uturn-left')
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('Registrar:Devolucion'))
            ->visible(fn (): bool => $venta->estaCompletada() && $venta->tipo_comprobante === TipoComprobante::NotaVenta && $venta->entregada_en !== null)
            ->disabled(fn (): bool => ! ($venta->politica_devolucion_snapshot['acepta_devoluciones'] ?? false)
                || (! ($venta->politica_devolucion_snapshot['permite_reembolso'] ?? false) && ! ($venta->politica_devolucion_snapshot['permite_saldo_favor'] ?? false)))
            ->tooltip(fn (): ?string => ! ($venta->politica_devolucion_snapshot['acepta_devoluciones'] ?? false)
                ? 'La política guardada en esta venta no acepta devoluciones comerciales.'
                : ((! ($venta->politica_devolucion_snapshot['permite_reembolso'] ?? false) && ! ($venta->politica_devolucion_snapshot['permite_saldo_favor'] ?? false)) ? 'Esta venta solo permite cambio por otro producto. Ese flujo todavía está pendiente.' : null))
            ->modalHeading('Devolver productos · '.$venta->codigo)->modalWidth('4xl')
            ->modalDescription('Solo piezas recibidas en buen estado, que volverán al almacén original. Indicá 0 en los productos que no devuelve.')
            ->modalSubmitActionLabel('Confirmar devolución')
            ->fillForm(function () use ($venta): array {
                return ['lineas' => array_map(fn (array $linea): array => [
                    'indice' => $linea['indice'], 'producto' => $linea['sku'].' · '.$linea['producto'],
                    'disponible' => $linea['disponible'].' '.$linea['unidad'], 'cantidad' => '0',
                ], $this->devoluciones->disponibles($venta))];
            })
            ->steps([
                Step::make('Productos')->schema([
                    Repeater::make('lineas')->label('Productos que devuelve')->addable(false)->deletable(false)->reorderable(false)
                        ->schema([
                            Hidden::make('indice'),
                            TextInput::make('producto')->label('Producto')->disabled()->dehydrated(false),
                            TextInput::make('disponible')->label('Disponible para devolver')->disabled()->dehydrated(false),
                            TextInput::make('cantidad')->label('Cantidad a devolver')->inputMode('decimal')->required()->rule('regex:/^\d{1,10}(\.\d{1,4})?$/'),
                        ])->columns(3),
                    Select::make('resolucion')->label('Resolución')->required()->options(function () use ($venta): array {
                        $politica = $venta->politica_devolucion_snapshot ?? [];

                        return array_filter([
                            'reembolso' => ($politica['permite_reembolso'] ?? false) ? 'Reembolso por los métodos originales' : null,
                            'saldo_favor' => ($politica['permite_saldo_favor'] ?? false) ? 'Saldo a favor del cliente' : null,
                        ], fn ($valor): bool => $valor !== null);
                    })->helperText('En crédito primero se reduce la deuda. Lo pagado con saldo a favor se restituye como saldo. El cambio por otro producto se incorporará después.'),
                    Textarea::make('motivo')->label('Motivo de la devolución')->trim()->required()->maxLength(500),
                    Textarea::make('motivo_excepcion')->label('Motivo de la excepción al plazo')->trim()->maxLength(500)
                        ->helperText('Solo se usa si el plazo venció y tenés autorización para exceptuarlo.')
                        ->visible(fn (): bool => (bool) ($venta->politica_devolucion_snapshot['permite_excepcion'] ?? false) && (bool) auth('tenant')->user()?->can('Autorizar:ExcepcionDevolucion')),
                ])->afterValidation(function (Get $get, Set $set) use ($venta): void {
                    $usuario = auth('tenant')->user();
                    abort_unless($usuario instanceof TenantUser, 403);
                    try {
                        $resumen = $this->devoluciones->revisar($venta->fresh(), $get('lineas'), $get('resolucion'), (string) $get('motivo_excepcion'), $usuario);
                    } catch (ValidationException $exception) {
                        $this->error($exception);
                        throw new Halt;
                    }
                    $set('revision', $resumen['revision']);
                    $moneda = NegocioConfiguracion::codigoMoneda();
                    $productos = collect($resumen['lineas'])->map(fn (array $linea): string => $linea['sku'].' × '.$linea['cantidad_devolver'])->implode(' · ');
                    $medios = collect($resumen['pagos'])->map(fn (array $parte): string => ($get('resolucion') === 'saldo_favor' || $parte['saldo_favor'] ? 'Saldo a favor' : MetodoPago::from($parte['metodo'])->etiqueta()).': '.$moneda.' '.$parte['monto'])->implode(' · ');
                    $set('resumen', 'Reingresan al almacén original: '.$productos.'. Valor de lo devuelto: '.$moneda.' '.$resumen['total'].'. Deuda a reducir: '.$moneda.' '.$resumen['deuda'].'. Movimientos: '.($medios ?: 'sin movimiento de dinero').'.');
                    $set('confirmado', false);
                }),
                Step::make('Revisar devolución')->schema([
                    Hidden::make('revision')->required(), Hidden::make('resumen')->dehydrated(false),
                    Text::make(fn (Get $get): ?string => $get('resumen')),
                    Text::make('El reembolso de dinero se registra en tu caja de hoy, por los métodos originales. Confirmá después de realizar la devolución indicada. Un saldo a favor no registra salida de dinero.'),
                    Checkbox::make('confirmado')->label('Recibí las piezas en buen estado y revisé los movimientos')->accepted(),
                ]),
            ])
            ->action(function (array $data, Action $action) use ($venta): void {
                $usuario = auth('tenant')->user();
                abort_unless($usuario instanceof TenantUser, 403);
                try {
                    $this->devoluciones->confirmar($venta, $data['lineas'], $data['resolucion'], $data['motivo'], (string) ($data['motivo_excepcion'] ?? ''), $data['revision'], $usuario);
                } catch (ValidationException $exception) {
                    $this->error($exception);
                    $action->halt();

                    return;
                }
                $venta->refresh();
                Notification::make()->title('Devolución registrada')->success()->send();
            });
    }

    private function error(ValidationException $exception): void
    {
        Notification::make()->title('Revisá la devolución')->body(collect($exception->errors())->flatten()->first())->danger()->send();
    }
}
