<?php

namespace App\Filament\Negocio\Pages;

use App\Caja\CerrarCajaDia;
use App\Caja\ConsolidarCajasDelDia;
use App\Compras\Montos;
use App\Models\CajaDia;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CajasDelDia extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Cajas del día';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Cajas del día';

    protected static ?string $slug = 'cajas-del-dia';

    protected string $view = 'filament.negocio.pages.cajas-del-dia';

    /**
     * @var array{sucursal_id?: int|string|null, fecha?: string|null}|null
     */
    public ?array $data = [];

    public ?int $vendedorId = null;

    public ?int $sesionId = null;

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser
            && $usuario->can('View:CajasDelDia');
    }

    public function mount(): void
    {
        $this->form->fill([
            'sucursal_id' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->value('id'),
            'fecha' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                        ->native(false)
                        ->live(),
                    DatePicker::make('fecha')
                        ->label('Día')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live(),
                ]),
            ])
            ->statePath('data');
    }

    public function getSubheading(): ?string
    {
        return 'Vista de supervisor: vendedores del día, sus sesiones y las ventas de cada sesión.';
    }

    public function updatedData(): void
    {
        $this->vendedorId = null;
        $this->sesionId = null;
        $this->dispatch('close-modal', id: 'turnos-vendedor');
        $this->dispatch('close-modal', id: 'ventas-sesion');
    }

    public function verVendedor(int $userId): void
    {
        $this->vendedorId = $userId;
        $this->sesionId = null;
        $this->dispatch('close-modal', id: 'ventas-sesion');
        $this->dispatch('open-modal', id: 'turnos-vendedor');
    }

    public function verSesion(int $sesionId): void
    {
        $this->sesionId = $sesionId;
        $this->dispatch('open-modal', id: 'ventas-sesion');
    }

    public function cerrarDia(): void
    {
        $usuario = auth('tenant')->user();
        $consolidado = $this->consolidado();

        abort_unless(
            $usuario instanceof TenantUser
            && $usuario->can('View:CajasDelDia')
            && $consolidado !== null,
            403,
        );

        app(CerrarCajaDia::class)->cerrar($consolidado['sucursal_id'], $consolidado['fecha'], $usuario);

        Notification::make()
            ->title('Día cerrado')
            ->body('No se abren más sesiones de esta fecha en la sucursal.')
            ->success()
            ->send();
    }

    public function cierreDia(): ?CajaDia
    {
        $consolidado = $this->consolidado();

        if ($consolidado === null) {
            return null;
        }

        return CajaDia::query()
            ->with('usuario')
            ->where('sucursal_id', $consolidado['sucursal_id'])
            ->whereDate('fecha', $consolidado['fecha'])
            ->first();
    }

    /**
     * @return array{
     *     sucursal_id: int,
     *     sucursal: string,
     *     fecha: string,
     *     sesiones_abiertas: int,
     *     sesiones_cerradas: int,
     *     total_apertura: string,
     *     total_cobros: string,
     *     total_reembolsos: string,
     *     por_metodo: list<array<string, mixed>>,
     *     sesiones: list<array<string, mixed>>
     * }|null
     */
    public function consolidado(): ?array
    {
        $sucursalId = $this->data['sucursal_id'] ?? null;
        $fecha = $this->data['fecha'] ?? null;

        if (! is_numeric($sucursalId) || ! is_string($fecha) || $fecha === '') {
            return null;
        }

        return app(ConsolidarCajasDelDia::class)->de((int) $sucursalId, $fecha);
    }

    /**
     * Una fila por vendedor. Las sesiones se abren aparte.
     *
     * @return list<array{
     *     user_id: int,
     *     cajero: string,
     *     sesiones: int,
     *     abiertas: int,
     *     pendientes: int,
     *     cerradas: int,
     *     monto_apertura: string,
     *     total_cobros: string,
     *     diferencia_efectivo: string|null
     * }>
     */
    public function vendedores(): array
    {
        $consolidado = $this->consolidado();

        if ($consolidado === null) {
            return [];
        }

        /** @var array<int, array{user_id: int, cajero: string, sesiones: int, abiertas: int, pendientes: int, cerradas: int, monto_apertura: string, total_cobros: string, diferencia_efectivo: string|null}> $grupos */
        $grupos = [];

        foreach ($consolidado['sesiones'] as $sesion) {
            $userId = (int) $sesion['user_id'];

            if (! isset($grupos[$userId])) {
                $grupos[$userId] = [
                    'user_id' => $userId,
                    'cajero' => (string) $sesion['cajero'],
                    'sesiones' => 0,
                    'abiertas' => 0,
                    'pendientes' => 0,
                    'cerradas' => 0,
                    'monto_apertura' => '0.00',
                    'total_cobros' => '0.00',
                    'diferencia_efectivo' => null,
                    'sin_declaracion' => false,
                ];
            }

            $grupos[$userId]['sesiones']++;

            if ($sesion['abierta']) {
                $grupos[$userId]['abiertas']++;
            } elseif ($sesion['pendiente']) {
                $grupos[$userId]['pendientes']++;
            } else {
                $grupos[$userId]['cerradas']++;
            }

            $grupos[$userId]['monto_apertura'] = Montos::sumar($grupos[$userId]['monto_apertura'], (string) $sesion['monto_apertura'], 2);
            $grupos[$userId]['total_cobros'] = Montos::sumar($grupos[$userId]['total_cobros'], (string) $sesion['total_cobros'], 2);

            if ($sesion['sin_declaracion']) {
                $grupos[$userId]['sin_declaracion'] = true;
            }

            if (is_string($sesion['diferencia_efectivo'])) {
                $grupos[$userId]['diferencia_efectivo'] = Montos::sumar(
                    $grupos[$userId]['diferencia_efectivo'] ?? '0.00',
                    $sesion['diferencia_efectivo'],
                    2,
                );
            }
        }

        $filas = array_values($grupos);
        usort($filas, fn (array $a, array $b): int => strcasecmp($a['cajero'], $b['cajero']));

        return $filas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sesionesDelVendedor(): array
    {
        $consolidado = $this->consolidado();

        if ($consolidado === null || $this->vendedorId === null) {
            return [];
        }

        return array_values(array_filter(
            $consolidado['sesiones'],
            fn (array $sesion): bool => (int) $sesion['user_id'] === $this->vendedorId,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sesionSeleccionada(): ?array
    {
        if ($this->sesionId === null) {
            return null;
        }

        foreach ($this->sesionesDelVendedor() as $sesion) {
            if ((int) $sesion['id'] === $this->sesionId) {
                return $sesion;
            }
        }

        return null;
    }

    public function moneda(): string
    {
        return NegocioConfiguracion::codigoMoneda();
    }

    public function textoCuadre(?string $diferencia, bool $sinDeclaracion = false): string
    {
        if ($diferencia === null) {
            return '—';
        }

        if ($diferencia === '0.00') {
            return $sinDeclaracion ? 'Sin declaración' : 'Cuadra';
        }

        $monto = $this->moneda().' '.ltrim($diferencia, '-');
        $resultado = str_starts_with($diferencia, '-') ? 'Falta '.$monto : 'Sobra '.$monto;

        return $sinDeclaracion ? 'Sin declaración, '.lcfirst($resultado) : $resultado;
    }

    public function tonoCuadre(?string $diferencia, bool $sinDeclaracion = false): string
    {
        if (is_string($diferencia) && str_starts_with($diferencia, '-')) {
            return 'danger';
        }

        if ($sinDeclaracion || (is_string($diferencia) && $diferencia !== '0.00')) {
            return 'warning';
        }

        return 'muted';
    }
}
