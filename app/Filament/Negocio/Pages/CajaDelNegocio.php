<?php

namespace App\Filament\Negocio\Pages;

use App\Caja\AbrirCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class CajaDelNegocio extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Caja';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Caja';

    protected static ?string $slug = 'caja';

    protected string $view = 'filament.negocio.pages.caja-del-negocio';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser
            && $usuario->can('viewAny', CajaSesion::class);
    }

    public function mount(): void
    {
        $this->rellenarFormulario();
    }

    public function form(Schema $schema): Schema
    {
        $moneda = NegocioConfiguracion::codigoMoneda();

        return $schema
            ->components([
                Section::make('Abrir caja')
                    ->description('Una abierta por sucursal y cajero. Sin ventas, el teórico al cerrar = apertura.')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->compact()
                    ->visible(fn (): bool => $this->sesionAbierta() === null)
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])->schema([
                            Select::make('sucursal_id')
                                ->label('Sucursal')
                                ->options(fn (): array => Sucursal::query()
                                    ->where('is_active', true)
                                    ->orderBy('nombre')
                                    ->pluck('nombre', 'id')
                                    ->all())
                                ->required()
                                ->searchable()
                                ->native(false),
                            TextInput::make('monto_apertura')
                                ->label('Monto de apertura')
                                ->prefix($moneda)
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->default('0'),
                        ]),
                    ]),
                Section::make('Cerrar caja')
                    ->description('Contá el efectivo. Si no calza con el teórico, justificá la diferencia.')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->compact()
                    ->visible(fn (): bool => $this->sesionAbierta() instanceof CajaSesion)
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 3,
                        ])->schema([
                            Placeholder::make('sucursal_abierta')
                                ->label('Sucursal')
                                ->content(fn (): string => $this->sesionAbierta()?->sucursal?->nombre ?? '—'),
                            Placeholder::make('apertura_mostrada')
                                ->label('Apertura')
                                ->content(fn (): string => $moneda.' '.Montos::dos((string) ($this->sesionAbierta()?->monto_apertura ?? '0'))),
                            Placeholder::make('teorico_mostrado')
                                ->label('Teórico')
                                ->content(fn (): string => $moneda.' '.Montos::dos((string) ($this->sesionAbierta()?->monto_apertura ?? '0'))),
                        ]),
                        TextInput::make('monto_cierre_real')
                            ->label('Efectivo contado')
                            ->prefix($moneda)
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true),
                        Textarea::make('justificacion_diferencia')
                            ->label('Justificación de la diferencia')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(function (Get $get): bool {
                                $sesion = $this->sesionAbierta();

                                if (! $sesion instanceof CajaSesion) {
                                    return false;
                                }

                                $real = $get('monto_cierre_real');

                                if (! is_numeric($real)) {
                                    return false;
                                }

                                $diferencia = Montos::dos(bcsub(Montos::dos($real), Montos::dos((string) $sesion->monto_apertura), 4));

                                return Montos::comparar($diferencia, '0', 2) !== 0;
                            })
                            ->required(function (Get $get): bool {
                                $sesion = $this->sesionAbierta();

                                if (! $sesion instanceof CajaSesion || ! is_numeric($get('monto_cierre_real'))) {
                                    return false;
                                }

                                $diferencia = Montos::dos(bcsub(
                                    Montos::dos($get('monto_cierre_real')),
                                    Montos::dos((string) $sesion->monto_apertura),
                                    4,
                                ));

                                return Montos::comparar($diferencia, '0', 2) !== 0;
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function abrir(): void
    {
        $usuario = auth('tenant')->user();

        abort_unless($usuario instanceof TenantUser && $usuario->can('create', CajaSesion::class), 403);

        app(AbrirCajaSesion::class)->abrir($this->form->getState(), $usuario);

        Notification::make()
            ->title('Caja abierta')
            ->success()
            ->send();

        $this->rellenarFormulario();
    }

    public function cerrar(): void
    {
        $usuario = auth('tenant')->user();
        $sesion = $this->sesionAbierta();

        abort_unless(
            $usuario instanceof TenantUser
            && $sesion instanceof CajaSesion
            && $usuario->can('update', $sesion),
            403,
        );

        app(CerrarCajaSesion::class)->cerrar($sesion, $this->form->getState(), $usuario);

        Notification::make()
            ->title('Caja cerrada')
            ->success()
            ->send();

        $this->rellenarFormulario();
    }

    /**
     * @return Collection<int, CajaSesion>
     */
    public function historial(): Collection
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return collect();
        }

        return CajaSesion::query()
            ->with('sucursal')
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Cerrada)
            ->orderByDesc('fecha_cierre')
            ->limit(10)
            ->get();
    }

    public function moneda(): string
    {
        return NegocioConfiguracion::codigoMoneda();
    }

    public function tieneSesionAbierta(): bool
    {
        return $this->sesionAbierta() instanceof CajaSesion;
    }

    private function sesionAbierta(): ?CajaSesion
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return null;
        }

        return CajaSesion::query()
            ->with('sucursal')
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->orderByDesc('fecha_apertura')
            ->first();
    }

    private function rellenarFormulario(): void
    {
        $sesion = $this->sesionAbierta();

        if ($sesion instanceof CajaSesion) {
            $this->form->fill([
                'monto_cierre_real' => Montos::dos((string) $sesion->monto_apertura),
                'justificacion_diferencia' => null,
            ]);

            return;
        }

        $this->form->fill([
            'sucursal_id' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->value('id'),
            'monto_apertura' => '0.00',
        ]);
    }
}
