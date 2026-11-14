<?php

namespace App\Filament\Negocio\Pages;

use App\Enums\ModoMoneda;
use App\Enums\Moneda;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ConfiguracionDelNegocio extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Configuración';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?string $title = 'Configuración del negocio';

    protected static ?string $slug = 'configuracion';

    protected string $view = 'filament.negocio.pages.configuracion-del-negocio';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $user = auth()->user();
        $configuracion = NegocioConfiguracion::query()->first();

        return $user instanceof TenantUser
            && $configuracion instanceof NegocioConfiguracion
            && $user->can('view', $configuracion);
    }

    public function mount(): void
    {
        $configuracion = NegocioConfiguracion::query()->firstOrFail();

        $this->form->fill([
            'horas_reserva' => $configuracion->horas_reserva,
            'acepta_devoluciones' => $configuracion->acepta_devoluciones,
            'dias_devolucion' => $configuracion->dias_devolucion,
            'devolucion_permite_cambio' => $configuracion->devolucion_permite_cambio,
            'devolucion_permite_saldo_favor' => $configuracion->devolucion_permite_saldo_favor,
            'devolucion_permite_reembolso' => $configuracion->devolucion_permite_reembolso,
            'devolucion_permite_excepcion' => $configuracion->devolucion_permite_excepcion,
            'permite_reembolso_senas' => $configuracion->permite_reembolso_senas,
            'descuento_max_porcentaje' => $configuracion->descuento_max_porcentaje,
            'descuento_max_importe' => $configuracion->descuento_max_importe,
            'moneda' => $configuracion->moneda->value,
            'modo_moneda' => $configuracion->modo_moneda->value,
            'comprobante_obligatorio_qr_externo' => $configuracion->comprobante_obligatorio_qr_externo,
            'comprobante_obligatorio_transferencia' => $configuracion->comprobante_obligatorio_transferencia,
            'comprobante_obligatorio_voucher_tarjeta' => $configuracion->comprobante_obligatorio_voucher_tarjeta,
            'permite_gastos_cajon_venta' => $configuracion->permite_gastos_cajon_venta,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Configuración')->tabs([
                    Tab::make('General')->icon(Heroicon::OutlinedCog6Tooth)->schema([
                        TextInput::make('horas_reserva')
                            ->label('Horas de reserva')
                            ->helperText('Plazo de partida de una seña. Cada reserva puede durar menos o más.')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('descuento_max_porcentaje')
                            ->label('Descuento máximo (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Toggle::make('permite_reembolso_senas')
                            ->label('Permitir reembolso de señas vencidas')
                            ->helperText('Se guarda esta condición en cada reserva nueva. Reembolsar exige permiso y motivo; el saldo también puede usarse en otra compra.')
                            ->inline(false),
                        TextInput::make('descuento_max_importe')
                            ->label('Descuento máximo en importe')
                            ->prefix(fn (Get $get): string => (string) ($get('moneda') ?: NegocioConfiguracion::codigoMoneda()))
                            ->helperText('En 0, un importe fijo lo autoriza el supervisor.')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('moneda')
                            ->label('Moneda de la tienda')
                            ->live()
                            ->options(collect(Moneda::cases())->mapWithKeys(
                                fn (Moneda $moneda): array => [$moneda->value => $moneda->etiqueta()],
                            )->all())
                            ->required()
                            ->native(false),
                        Select::make('modo_moneda')
                            ->label('Dólar')
                            ->helperText('Habilita precios de referencia y costos de proveedor en USD. El mostrador cobra en la moneda de la tienda.')
                            ->options(collect(ModoMoneda::cases())->mapWithKeys(
                                fn (ModoMoneda $modo): array => [$modo->value => $modo->etiqueta()],
                            )->all())
                            ->required()
                            ->native(false),
                        Toggle::make('comprobante_obligatorio_qr_externo')
                            ->label('Comprobante obligatorio en QR')
                            ->helperText('PDF o imagen. Si está apagado, el adjunto es opcional.')
                            ->inline(false),
                        Toggle::make('comprobante_obligatorio_transferencia')
                            ->label('Comprobante obligatorio en transferencia')
                            ->helperText('PDF o imagen. La referencia del banco sigue siendo obligatoria.')
                            ->inline(false),
                        Toggle::make('comprobante_obligatorio_voucher_tarjeta')
                            ->label('Comprobante obligatorio en voucher')
                            ->helperText('PDF o imagen. El número del voucher sigue siendo obligatorio.')
                            ->inline(false),
                        Toggle::make('permite_gastos_cajon_venta')
                            ->label('Permitir gastos desde el cajón de venta')
                            ->helperText('Apagado, la caja chica independiente sigue operando. No salen gastos ni vales nuevos desde los turnos.')
                            ->inline(false),
                    ]),
                    Tab::make('Devoluciones')->icon(Heroicon::OutlinedArrowUturnLeft)->schema([
                        Section::make('Política de devoluciones comerciales')
                            ->afterHeader([
                                Icon::make(Heroicon::OutlinedQuestionMarkCircle)->color('gray')
                                    ->tooltip('Define si el negocio acepta devoluciones comerciales y hasta cuándo. Por ejemplo: 0 permite devolver el mismo día de entrega; 7 permite hasta 7 días adicionales. Desactivarlo conserva la configuración y no bloquea reclamos por fallas o garantía.')
                                    ->extraAttributes(['tabindex' => '0', 'aria-label' => 'Ayuda sobre la política de devoluciones']),
                            ])
                            ->description('Condiciones para productos que el cliente desea devolver. Los reclamos por fallas o garantía se tratan por separado.')
                            ->schema([
                                Toggle::make('acepta_devoluciones')->label('Aceptar devoluciones comerciales')->live()->inline(false),
                                TextInput::make('dias_devolucion')->label('Días para devolver')->numeric()->integer()->minValue(0)->maxValue(3650)->required()
                                    ->disabled(fn (Get $get): bool => ! $get('acepta_devoluciones'))
                                    ->helperText('0 permite devolver solo el mismo día de entrega. Otro valor permite esa cantidad de días adicionales, hasta las 23:59 del último día.'),
                            ]),
                        Section::make('Resoluciones permitidas')
                            ->afterHeader([
                                Icon::make(Heroicon::OutlinedQuestionMarkCircle)->color('gray')
                                    ->tooltip('Elegí qué puede ofrecer el negocio al aceptar una devolución: otro producto, saldo para una compra futura o devolución del dinero. Podés permitir varias opciones. Esto no concede permisos al vendedor.')
                                    ->extraAttributes(['tabindex' => '0', 'aria-label' => 'Ayuda sobre las resoluciones permitidas']),
                            ])
                            ->description('Elegí al menos una opción si aceptás devoluciones. Son condiciones comerciales, no permisos del vendedor.')
                            ->schema([
                                Toggle::make('devolucion_permite_cambio')->label('Cambio por otro producto')->inline(false)
                                    ->disabled(fn (Get $get): bool => ! $get('acepta_devoluciones')),
                                Toggle::make('devolucion_permite_saldo_favor')->label('Saldo a favor del cliente')->inline(false)
                                    ->disabled(fn (Get $get): bool => ! $get('acepta_devoluciones')),
                                Toggle::make('devolucion_permite_reembolso')->label('Devolución del dinero')->inline(false)
                                    ->disabled(fn (Get $get): bool => ! $get('acepta_devoluciones')),
                            ]),
                        Section::make('Excepciones')
                            ->afterHeader([
                                Icon::make(Heroicon::OutlinedQuestionMarkCircle)->color('gray')
                                    ->tooltip('Ejemplo: el plazo es de 7 días y el cliente vuelve al día 8. Apagado, el plazo vencido bloquea la devolución. Encendido, alguien con el permiso especial podrá autorizarla dejando un motivo. No permite devolver más unidades o dinero de lo disponible ni aceptar devoluciones desactivadas por el negocio.')
                                    ->extraAttributes(['tabindex' => '0', 'aria-label' => 'Ayuda sobre las excepciones al plazo']),
                            ])
                            ->schema([
                                Toggle::make('devolucion_permite_excepcion')->label('Permitir excepciones al plazo')->inline(false)
                                    ->disabled(fn (Get $get): bool => ! $get('acepta_devoluciones'))
                                    ->helperText('Se exigirá un permiso especial y un motivo. No habilita devoluciones cuando el negocio no las acepta ni permite superar cantidades o importes disponibles.'),
                            ]),
                    ]),
                ])->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $configuracion = NegocioConfiguracion::query()->firstOrFail();
        $user = auth()->user();

        abort_unless($user instanceof TenantUser && $user->can('update', $configuracion), 403);

        $datos = $this->form->getState();
        if ($datos['acepta_devoluciones'] && ! $datos['devolucion_permite_cambio'] && ! $datos['devolucion_permite_saldo_favor'] && ! $datos['devolucion_permite_reembolso']) {
            throw ValidationException::withMessages(['data.devolucion_permite_cambio' => 'Elegí al menos una resolución para aceptar devoluciones.']);
        }
        DB::transaction(function () use ($datos): void {
            $configuracion = NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();
            if (($datos['modo_moneda'] === ModoMoneda::SoloMoneda->value || $datos['moneda'] !== $configuracion->moneda->value)
                && ProductoVariante::query()->where(fn ($query) => $query->whereNotNull('precio_venta_dolar')->orWhereNotNull('precio_mayor_dolar'))->exists()) {
                throw ValidationException::withMessages([
                    'data.modo_moneda' => 'Pasá los precios referenciados a valores fijos antes de desactivar el dólar o cambiar la moneda de la tienda.',
                ]);
            }
            $configuracion->update($datos);
        });

        Notification::make()
            ->title('Configuración guardada')
            ->success()
            ->send();
    }
}
