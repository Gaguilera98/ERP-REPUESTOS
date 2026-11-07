<?php

namespace App\Livewire\Negocio\Caja;

use App\Compras\Montos;
use App\Enums\MetodoPago;
use App\Enums\TipoComprobante;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\CorregirVenta;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AccionCorregirVenta
{
    public function __construct(private CorregirVenta $ventas) {}

    public function para(Venta $venta): Action
    {
        return Action::make('corregirVenta')
            ->label('Corregir productos o cantidades')
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('corregir', $venta))
            ->visible(fn (): bool => $venta->estaCompletada() && $venta->tipo_comprobante === TipoComprobante::NotaVenta)
            ->modalHeading('Corregir venta · '.$venta->codigo)
            ->modalDescription('Usá esta acción para corregir lo que se registró en el mostrador. Para una devolución física corresponde el flujo de devoluciones.')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Confirmar corrección')
            ->fillForm(fn (): array => ['lineas' => collect($this->ventas->lineasVigentes($venta))
                ->map(fn (array $linea, int $origen): array => [
                    'origen' => $origen, 'producto_variante_id' => $linea['producto_variante_id'],
                    'cantidad' => Montos::sinCeros($linea['cantidad']),
                ])->all()])
            ->steps([
                Step::make('Productos')->schema([
                    Repeater::make('lineas')->label('Productos de la venta')->minItems(1)->maxItems(200)
                        ->helperText('Para reemplazar un producto, elegí el correcto en la misma fila.')
                        ->reorderable(false)->addActionLabel('Agregar producto')->columns(2)->schema([
                            Hidden::make('origen'),
                            Select::make('producto_variante_id')->label('Producto')->required()->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => ProductoVariante::query()
                                    ->with('productoMaestro')->where('is_active', true)
                                    ->where(fn (Builder $query): Builder => $query->whereLike('sku', '%'.$search.'%')
                                        ->orWhereHas('productoMaestro', fn (Builder $productos): Builder => $productos->whereLike('nombre', '%'.$search.'%')))
                                    ->orderBy('sku')->limit(30)->get()->mapWithKeys(fn (ProductoVariante $variante): array => [
                                        $variante->id => $this->etiquetaProducto($variante),
                                    ])->all())
                                ->getOptionLabelUsing(fn ($value): ?string => $this->etiquetaProducto(ProductoVariante::query()->with('productoMaestro')->find($value))),
                            TextInput::make('cantidad')->label('Cantidad')->inputMode('decimal')->required()
                                ->rule('regex:/^\d{1,10}(\.\d{1,4})?$/'),
                        ]),
                    Textarea::make('motivo')->label('Motivo de la corrección')->trim()->required()->maxLength(500)
                        ->validationMessages(['required' => 'Escribí el motivo para continuar.']),
                ])->afterValidation(function (Get $get, Set $set) use ($venta): void {
                    $usuario = auth('tenant')->user();
                    abort_unless($usuario instanceof TenantUser && $usuario->can('corregir', $venta), 403);

                    try {
                        $resumen = $this->ventas->revisar($venta, $get('lineas'));
                        $this->ventas->revisarCaja($venta, $resumen, $usuario);
                    } catch (ValidationException $exception) {
                        $this->mostrarError($exception);
                        throw new Halt;
                    }

                    $set('revision', $this->ventas->revision($venta, $resumen));
                    $set('resumen', $this->textoRevision($venta, $resumen));
                    $set('confirmado', false);
                }),
                Step::make('Revisar corrección')->schema([
                    Hidden::make('revision')->required(),
                    Hidden::make('resumen')->dehydrated(false),
                    Text::make(fn (Get $get): ?string => $get('resumen')),
                    Checkbox::make('confirmado')->label('Revisé los cambios y confirmo los movimientos indicados')->accepted(),
                ]),
            ])
            ->action(function (array $data, Action $action) use ($venta): void {
                $usuario = auth('tenant')->user();
                abort_unless($usuario instanceof TenantUser, 403);

                try {
                    $this->ventas->confirmar($venta, $data['lineas'], $data['motivo'], $data['revision'], $usuario);
                } catch (ValidationException $exception) {
                    $this->mostrarError($exception);
                    $action->halt();

                    return;
                }

                $venta->refresh();
                Notification::make()->title('Corrección registrada')->success()->send();
            });
    }

    private function etiquetaProducto(?ProductoVariante $variante): ?string
    {
        return $variante ? $variante->sku.' · '.$variante->productoMaestro?->nombre : null;
    }

    private function textoRevision(Venta $venta, array $resumen): string
    {
        $moneda = NegocioConfiguracion::codigoMoneda();
        $montos = "Total anterior {$moneda} {$venta->totalVigente()}. Total corregido {$moneda} {$resumen['total']}. Pagos registrados {$moneda} {$resumen['cobrado']}. Saldo pendiente {$moneda} {$resumen['saldo']}.";
        $movimientos = collect($resumen['cambios'])->map(function (array $cambio): string {
            $accion = $cambio['costo'] === null ? 'Sale' : 'Reingresa';

            return $accion.' '.Montos::sinCeros(ltrim($cambio['cantidad'], '-')).' de '.$cambio['sku'];
        })->implode(' · ');
        $kardex = "Almacén original: {$movimientos}.";
        if ($resumen['cobrar'] !== '0.00') {
            return "{$montos} {$kardex} Cobrá en efectivo {$moneda} {$resumen['cobrar']} y registralo en tu caja de hoy.";
        }
        if ($resumen['devolver'] !== '0.00') {
            $medios = collect($resumen['reembolsos'])->groupBy(fn (array $pago): string => $pago['saldo_favor'] ? 'saldo_favor' : $pago['metodo'])->map(function ($pagos, string $metodo) use ($moneda): string {
                $monto = $pagos->reduce(fn (string $total, array $pago): string => bcadd($total, $pago['monto'], 2), '0.00');

                return ($metodo === 'saldo_favor' ? 'Restituir saldo a favor' : MetodoPago::from($metodo)->etiqueta())." {$moneda} {$monto}";
            })->implode(', ');

            return "{$montos} {$kardex} Reintegro a registrar: {$medios}.";
        }

        return "{$montos} {$kardex} Sin movimiento de dinero.";
    }

    private function mostrarError(ValidationException $exception): void
    {
        Notification::make()->title('Revisá la corrección')
            ->body(collect($exception->errors())->flatten()->first())->danger()->send();
    }
}
