<?php

namespace App\Livewire\Negocio\Caja;

use App\Clientes\BuscarClientesMostrador;
use App\Compras\Montos;
use App\Enums\MetodoPago;
use App\Models\Cliente;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\BuscarProductosMostrador;
use App\Ventas\CambiarProductoVenta;
use App\Ventas\RegistrarDevolucion;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class AccionCambiarProducto
{
    public function __construct(
        private CambiarProductoVenta $cambios,
        private RegistrarDevolucion $devoluciones,
        private BuscarProductosMostrador $productos,
        private BuscarClientesMostrador $clientes,
    ) {}

    public function para(Venta $venta): Action
    {
        $politica = $venta->politica_devolucion_snapshot;
        $permiteCambio = ($politica['acepta_devoluciones'] ?? false) && ($politica['permite_cambio'] ?? false);
        $motivoBloqueo = fn (): ?string => match (true) {
            ! $permiteCambio => 'La política guardada en esta venta no permite cambios.',
            ! $this->devoluciones->tieneUnidadesDisponibles($venta) => $this->devoluciones->motivoSinPiezasDisponibles($venta),
            default => null,
        };

        return Action::make('cambiarProducto')
            ->label('Cambiar producto')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('Registrar:Devolucion')
                && (bool) auth('tenant')->user()?->can('view', $venta)
                && (bool) auth('tenant')->user()?->can('create', Venta::class))
            ->visible(fn (): bool => $this->cambios->admite($venta))
            ->disabled(fn (): bool => $motivoBloqueo() !== null)
            ->tooltip($motivoBloqueo)
            ->modalHeading('Cambiar producto · '.$venta->codigo)
            ->modalWidth('4xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Recibí la pieza en buen estado y elegí el reemplazo del mismo almacén.')
            ->modalSubmitActionLabel('Confirmar cambio')
            ->fillForm(fn (): array => [
                'lineas' => $this->devoluciones->lineasParaFormulario($venta),
                'reemplazo' => ['cantidad' => '1'],
            ])
            ->steps([$this->productosDelCambio($venta), $this->confirmacion($venta)])
            ->modifyWizardUsing(fn ($wizard) => $wizard->nextAction(fn (Action $action): Action => $action->label('Revisar cambio')))
            ->action(function (array $data, Action $action) use ($venta): void {
                try {
                    $reemplazo = $this->cambios->confirmar($venta, $data, $this->usuario());
                } catch (ValidationException $exception) {
                    $this->mostrarError($exception);
                    $action->halt();

                    return;
                }

                $venta->refresh();
                $notificacion = Notification::make()->title('Cambio registrado')->body('Reemplazo '.$reemplazo->codigo)->success();
                $vuelto = $reemplazo->aplicacionesPago()->where('es_compensacion_cambio', false)
                    ->with('pago')->first()?->pago?->cambio_entregado;
                if ($vuelto !== null && bccomp($vuelto, '0', 2) > 0) {
                    $notificacion->body('Reemplazo '.$reemplazo->codigo.' · Vuelto '.NegocioConfiguracion::codigoMoneda().' '.$vuelto);
                }
                $notificacion->send();
            });
    }

    private function productosDelCambio(Venta $venta): Step
    {
        return Step::make('Productos')->schema([
            Repeater::make('lineas')->label('Piezas que recibe el negocio')
                ->addable(false)->deletable(false)->reorderable(false)
                ->helperText('Dejá en 0 las piezas que el cliente conserva.')
                ->schema([
                    Hidden::make('indice'),
                    Hidden::make('bloqueada')->dehydrated(false),
                    TextInput::make('producto')->label('Producto')->disabled()->dehydrated(false),
                    TextInput::make('disponible')->label('Disponible para devolver')->disabled()->dehydrated(false),
                    TextInput::make('cantidad')->label('Cantidad a devolver')->inputMode('decimal')->required()->rule('regex:/^\d{1,10}(\.\d{1,4})?$/')
                        ->disabled(fn (Get $get): bool => (bool) $get('bloqueada'))
                        ->helperText(fn (Get $get): ?string => $get('bloqueada') ? 'No retornable' : null),
                ])->columns(3),
            Section::make('Pieza de reemplazo')->columns(2)->schema([
                Select::make('reemplazo.producto_variante_id')->label('Producto')->required()->searchable()
                    ->searchPrompt('Buscá por nombre, SKU o código de barras')
                    ->getSearchResultsUsing(fn (string $search): array => collect($this->productos->sugerir([
                        'texto' => $search, 'almacen_id' => $venta->almacen_id, 'lista' => $venta->cliente?->tipo_precio,
                    ])['items'])->mapWithKeys(fn (array $producto): array => [
                        $producto['id'] => $producto['sku'].' · '.$producto['nombre'].' · Stock '.Montos::sinCeros($producto['disponible']).' '.$producto['unidad_simbolo'],
                    ])->all())
                    ->getOptionLabelUsing(function ($value): ?string {
                        $variante = ProductoVariante::query()->with('productoMaestro')->find($value);

                        return $variante ? $variante->sku.' · '.$variante->productoMaestro?->nombre : null;
                    }),
                TextInput::make('reemplazo.cantidad')->label('Cantidad')->inputMode('decimal')->required()->rule('regex:/^\d{1,10}(\.\d{1,4})?$/')
                    ->helperText('En la unidad base del reemplazo.'),
            ]),
            Textarea::make('motivo')->label('Motivo del cambio')->trim()->required()->maxLength(500),
            Textarea::make('motivo_excepcion')->label('Motivo de la excepción al plazo')->trim()->maxLength(500)
                ->visible(fn (): bool => (bool) ($venta->politica_devolucion_snapshot['permite_excepcion'] ?? false)
                    && (bool) auth('tenant')->user()?->can('Autorizar:ExcepcionDevolucion')),
        ])->afterValidation(function (Get $get, Set $set) use ($venta): void {
            try {
                $resumen = $this->cambios->revisar($venta, $get('lineas'), $get('reemplazo'), (string) $get('motivo_excepcion'), $this->usuario());
            } catch (ValidationException $exception) {
                $this->mostrarError($exception);
                throw new Halt;
            }

            $set('revision', $resumen['revision']);
            $set('resumen', [
                'devuelve' => collect($resumen['devolucion']['lineas'])->map(fn (array $linea): string => Montos::sinCeros($linea['cantidad_devolver']).' '.$linea['unidad'].' · '.$linea['sku'].' · '.$linea['producto'])->implode(' / '),
                'entrega' => Montos::sinCeros($resumen['reemplazo']['cantidad']).' '.$resumen['unidad'].' · '.$resumen['producto'],
                'valor_devuelto' => $resumen['devolucion']['total'],
                'precio_reemplazo' => $resumen['reemplazo']['total_linea'],
                'diferencia' => $resumen['diferencia'],
                'resoluciones' => $resumen['resoluciones'],
                'remanente' => $resumen['remanente'],
            ]);
            $set('resolucion', count($resumen['resoluciones']) === 1 ? array_key_first($resumen['resoluciones']) : null);
            $set('confirmado', false);
        });
    }

    private function confirmacion(Venta $venta): Step
    {
        $moneda = NegocioConfiguracion::codigoMoneda();
        $cobra = fn (Get $get): bool => bccomp($this->diferencia($get), '0', 2) > 0;
        $devuelve = fn (Get $get): bool => bccomp($this->diferencia($get), '0', 2) < 0;

        return Step::make('Confirmación')->schema([
            Hidden::make('revision')->required(),
            Hidden::make('resumen')->dehydrated(false),
            Section::make('Resumen del cambio')->schema([
                Text::make(fn (Get $get): string => 'Recibís: '.$get('resumen.devuelve')),
                Text::make(fn (Get $get): string => 'Entregás: '.$get('resumen.entrega')),
                Text::make(fn (Get $get): string => 'Valor devuelto '.$moneda.' '.$get('resumen.valor_devuelto').' · Reemplazo '.$moneda.' '.$get('resumen.precio_reemplazo')),
                Text::make(fn (Get $get): string => match (bccomp($this->diferencia($get), '0', 2)) {
                    1 => 'Cobrar '.$moneda.' '.$this->diferencia($get),
                    -1 => 'A favor del cliente '.$moneda.' '.bcsub('0', $this->diferencia($get), 2),
                    default => 'Mismo valor · Sin cobro ni reembolso',
                })->weight('bold'),
            ]),
            Select::make('resolucion')->label('Cómo resolver la diferencia')->options(fn (Get $get): array => $get('resumen.resoluciones') ?? [])
                ->visible($devuelve)->required()->live(),
            Text::make(fn (Get $get): string => collect($get('resumen.remanente') ?? [])->map(fn (array $parte): string => ($parte['saldo_favor'] ? 'Saldo a favor' : MetodoPago::tryFrom($parte['metodo'])?->etiqueta()).' · '.$moneda.' '.$parte['monto'])->implode(' / '))
                ->visible(fn (Get $get): bool => $devuelve($get) && $get('resolucion') === 'reembolso'),
            Text::make('Confirmá después de realizar el reembolso por los medios indicados.')
                ->visible(fn (Get $get): bool => $devuelve($get) && $get('resolucion') === 'reembolso'
                    && collect($get('resumen.remanente') ?? [])->contains(fn (array $parte): bool => ! $parte['saldo_favor'])),
            Select::make('cliente_saldo_id')->label('Cliente para el saldo a favor')->searchable()
                ->getSearchResultsUsing(fn (string $search): array => collect($this->clientes->sugerir($search)['items'])
                    ->mapWithKeys(fn (array $cliente): array => [$cliente['id'] => trim($cliente['nombre'].' '.$cliente['documento'])])->all())
                ->getOptionLabelUsing(fn ($value): ?string => Cliente::query()->find($value)?->nombre_mostrar)
                ->visible(fn (Get $get): bool => $devuelve($get) && $get('resolucion') === 'saldo_favor' && $venta->cliente_id === null)->required(),
            Select::make('metodo_pago')->label('Método de pago de la diferencia')
                ->options(collect(MetodoPago::cases())->mapWithKeys(fn (MetodoPago $metodo): array => [$metodo->value => $metodo->etiqueta()])->all())
                ->default(MetodoPago::Efectivo->value)->visible($cobra)->required()->live(),
            TextInput::make('efectivo_recibido')->label('Efectivo recibido')->prefix($moneda)->inputMode('decimal')
                ->rule('regex:/^\d{1,8}(\.\d{1,2})?$/')->live(onBlur: true)
                ->visible(fn (Get $get): bool => $cobra($get) && $get('metodo_pago') === MetodoPago::Efectivo->value)
                ->helperText(fn (Get $get): string => $this->vuelto($get, $moneda)),
            TextInput::make('numero_referencia_banco')->label('Referencia o número de voucher')->maxLength(255)->required()
                ->visible(fn (Get $get): bool => $cobra($get) && in_array($get('metodo_pago'), [MetodoPago::Transferencia->value, MetodoPago::VoucherTarjeta->value], true)),
            FileUpload::make('comprobante')->label('Comprobante')->disk('local')->directory('pagos')->visibility('private')->preventFilePathTampering()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(NegocioConfiguracion::COMPROBANTE_MAX_KB)
                ->visible(fn (Get $get): bool => $cobra($get) && (bool) $this->metodo($get)?->aceptaComprobanteArchivo())
                ->required(fn (Get $get): bool => ($metodo = $this->metodo($get)) && (bool) NegocioConfiguracion::query()->first()?->exigeComprobanteArchivo($metodo)),
            Checkbox::make('confirmado')->label('Recibí las piezas en buen estado y revisé la entrega y los importes')->accepted(),
        ]);
    }

    private function metodo(Get $get): ?MetodoPago
    {
        $metodo = $get('metodo_pago');

        return is_string($metodo) ? MetodoPago::tryFrom($metodo) : null;
    }

    private function diferencia(Get $get): string
    {
        $diferencia = $get('resumen.diferencia');

        return is_string($diferencia) && preg_match('/^-?\d+\.\d{2}$/', $diferencia) ? $diferencia : '0.00';
    }

    private function vuelto(Get $get, string $moneda): string
    {
        $recibido = $get('efectivo_recibido');
        if (! is_scalar($recibido) || ! preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $recibido)) {
            return 'Dejalo vacío si recibís el importe exacto.';
        }

        $vuelto = bcsub((string) $recibido, $this->diferencia($get), 2);

        return bccomp($vuelto, '0', 2) < 0 ? 'El efectivo recibido no alcanza.' : 'Vuelto '.$moneda.' '.$vuelto;
    }

    private function usuario(): TenantUser
    {
        $usuario = auth('tenant')->user();
        abort_unless($usuario instanceof TenantUser, 403);

        return $usuario;
    }

    private function mostrarError(ValidationException $exception): void
    {
        Notification::make()->title('Revisá el cambio')->body(collect($exception->errors())->flatten()->first())->danger()->send();
    }
}
