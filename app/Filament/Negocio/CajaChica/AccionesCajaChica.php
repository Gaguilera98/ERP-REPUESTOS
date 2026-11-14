<?php

namespace App\Filament\Negocio\CajaChica;

use App\Archivos\ArchivosNegocio;
use App\Caja\CerrarCajaSesion;
use App\CajaChica\AdjuntarComprobante;
use App\CajaChica\AutorizarOrigen;
use App\CajaChica\CorregirMovimiento;
use App\CajaChica\EntregarVale;
use App\CajaChica\FondosCajaChica;
use App\CajaChica\Permisos;
use App\CajaChica\RegistrarGasto;
use App\CajaChica\RendirVale;
use App\CajaChica\ReponerFondo;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoArchivoNegocio;
use App\Enums\TipoCajaChicaMovimiento;
use App\Enums\TipoComprobanteCajaChica;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\CategoriaGasto;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\Sucursal;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class AccionesCajaChica
{
    /**
     * @return list<Action>
     */
    public static function delTurno(): array
    {
        return [
            self::gasto(true),
            self::vale(true),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function delFondo(): array
    {
        return [
            self::gasto(false),
            self::vale(false)->color('gray'),
            ActionGroup::make([
                self::abrir(),
                self::rendir(),
                self::reponer(),
                self::adjuntar(),
                self::corregir(),
                self::entregar(),
                self::cerrarAjeno(),
            ])->label('Más acciones')->icon(Heroicon::OutlinedEllipsisHorizontal)->color('gray')->button(),
        ];
    }

    private static function gasto(bool $soloTurno): Action
    {
        return Action::make($soloTurno ? 'gastoTurno' : 'gastoFondo')
            ->label('Registrar gasto')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->visible(fn (): bool => self::puedeGastar($soloTurno) && ($soloTurno || self::tieneFondoPropio()))
            ->schema(fn (): array => [
                ...self::origen($soloTurno),
                Select::make('categoria_gasto_id')->label('Categoría')->options(self::categorias())->required()->native(false),
                TextInput::make('monto')->label('Monto')->numeric()->minValue(0.01)->required(),
                TextInput::make('concepto')->label('Concepto')->required()->maxLength(255),
                Select::make('orden_compra_id')->label('Compra que respalda')->options(self::compras())->searchable()->native(false),
                Toggle::make('sin_comprobante')->label('No va a haber comprobante')->live(),
                Toggle::make('ya_ocurrido')->label('El gasto ya ocurrió y puede superar el efectivo'),
                Textarea::make('motivo_excepcion')->label('Motivo')->visible(fn (callable $get): bool => (bool) $get('sin_comprobante')),
                self::comprobanteCajaChica('Comprobante')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']),
            ])
            ->action(function (array $data): void {
                $usuario = self::usuario();
                app(RegistrarGasto::class)->registrar($data, $usuario);
                Notification::make()->title('Gasto registrado')->success()->send();
            });
    }

    private static function vale(bool $soloTurno): Action
    {
        return Action::make($soloTurno ? 'valeTurno' : 'valeFondo')
            ->label('Entregar vale')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (): bool => self::puedeGastar($soloTurno) && ($soloTurno || self::tieneFondoPropio()))
            ->schema(fn (): array => [
                ...self::origen($soloTurno),
                TextInput::make('persona_recibe')->label('Quién recibe el efectivo')->required(),
                TextInput::make('monto')->label('Monto')->numeric()->minValue(0.01)->required(),
                TextInput::make('concepto')->label('Concepto')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                app(EntregarVale::class)->entregar($data, self::usuario());
                Notification::make()->title('Vale entregado')->success()->send();
            });
    }

    private static function rendir(): Action
    {
        return Action::make('rendirVale')
            ->label('Rendir vale')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->visible(fn (): bool => self::puedeGestionarRendiciones())
            ->fillForm(function (array $arguments): array {
                $id = (int) ($arguments['valeId'] ?? 0);

                if ($id < 1) {
                    return [];
                }

                $vale = CajaChicaMovimiento::query()
                    ->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
                    ->where('estado', EstadoCajaChicaMovimiento::PendienteRendir)
                    ->whereHas('fondo', fn ($fondo) => $fondo->where('responsable_id', self::usuario()->id))
                    ->findOrFail($id);

                return [
                    'vale_id' => $vale->id,
                    'destino_fondo_id' => $vale->fondo_id,
                ];
            })
            ->schema([
                Select::make('vale_id')->label('Vale pendiente')->options(fn (): array => app(AutorizarOrigen::class)->movimientosParaRendicion(self::usuario())
                    ->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
                    ->where('estado', EstadoCajaChicaMovimiento::PendienteRendir)
                    ->orderByDesc('id')
                    ->get()
                    ->mapWithKeys(fn (CajaChicaMovimiento $vale): array => [$vale->id => $vale->persona_recibe.' · '.$vale->monto.' · '.$vale->concepto])
                    ->all())->required()->native(false),
                TextInput::make('monto_gasto')->label('Gasto real')->numeric()->minValue(0)->required()
                    ->helperText('El gasto y el sobrante deben sumar el monto entregado.'),
                TextInput::make('monto_sobrante')->label('Sobrante devuelto')->numeric()->minValue(0)->required(),
                Select::make('categoria_gasto_id')->label('Categoría')->options(self::categorias())->native(false),
                Select::make('destino_fondo_id')->label('Fondo que recibe el sobrante')->options(self::fondos())->native(false)->live()
                    ->afterStateUpdated(fn (callable $set) => $set('destino_caja_sesion_id', null)),
                Select::make('destino_caja_sesion_id')->label('O turno abierto que lo recibe')->options(self::turnosAbiertos())->native(false)->live()
                    ->afterStateUpdated(fn (callable $set) => $set('destino_fondo_id', null)),
                Select::make('tipo_comprobante')->label('Comprobante')->options(self::tiposComprobante())->native(false),
                TextInput::make('numero_comprobante')->label('Número'),
                self::comprobanteCajaChica('Archivo'),
            ])
            ->action(function (array $data): void {
                $vale = CajaChicaMovimiento::query()->findOrFail($data['vale_id']);
                app(RendirVale::class)->rendir($vale, $data, self::usuario());
                Notification::make()->title('Vale rendido')->success()->send();
            });
    }

    private static function reponer(): Action
    {
        return Action::make('reponerFondo')
            ->label('Reponer fondo')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => self::usuarioOpcional()?->can(Permisos::OPERAR) || self::usuarioOpcional()?->can(Permisos::FONDOS))
            ->schema([
                Select::make('fondo_id')->label('Fondo')->options(self::fondos())->required()->native(false),
                TextInput::make('monto')->label('Monto')->numeric()->minValue(0.01)->required(),
                TextInput::make('procedencia')->label('De dónde sale')->required(),
                TextInput::make('concepto')->label('Concepto'),
                Select::make('caja_sesion_id')->label('Sale de un turno')->options(self::turnosAbiertos())->native(false),
                Select::make('fondo_origen_id')->label('O de otro fondo')->options(self::fondos())->native(false),
            ])
            ->action(function (array $data): void {
                $fondo = CajaChicaFondo::query()->findOrFail($data['fondo_id']);
                app(ReponerFondo::class)->reponer($fondo, $data, self::usuario());
                Notification::make()->title('Fondo repuesto')->success()->send();
            });
    }

    private static function abrir(): Action
    {
        return Action::make('abrirFondo')
            ->label('Abrir fondo')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => (bool) self::usuarioOpcional()?->can(Permisos::FONDOS))
            ->schema([
                Select::make('sucursal_id')->label('Sucursal')->options(fn (): array => self::sucursalesDelUsuario())->required()->native(false),
                Select::make('responsable_id')->label('Responsable')->options(fn (): array => TenantUser::query()->orderBy('name')->pluck('name', 'id')->all())->required()->native(false),
                TextInput::make('monto_apertura')->label('Saldo inicial')->numeric()->minValue(0)->required(),
            ])
            ->action(function (array $data): void {
                app(FondosCajaChica::class)->abrir($data, self::usuario());
                Notification::make()->title('Fondo abierto')->success()->send();
            });
    }

    private static function entregar(): Action
    {
        return Action::make('cambiarResponsable')
            ->label('Cambiar responsable')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn (): bool => (bool) self::usuarioOpcional()?->can(Permisos::FONDOS))
            ->schema([
                Select::make('fondo_id')->label('Fondo')->options(self::fondos())->required()->native(false),
                Select::make('responsable_id')->label('Quién recibe')->options(fn (): array => TenantUser::query()->orderBy('name')->pluck('name', 'id')->all())->required()->native(false),
                TextInput::make('contado')->label('Efectivo contado')->numeric()->minValue(0)->required(),
                Textarea::make('justificacion')->label('Justificación si no calza'),
            ])
            ->action(function (array $data): void {
                $fondo = CajaChicaFondo::query()->findOrFail($data['fondo_id']);
                app(FondosCajaChica::class)->cambiarResponsable($fondo, $data, self::usuario());
                Notification::make()->title('Responsable actualizado')->success()->send();
            });
    }

    private static function cerrarAjeno(): Action
    {
        $campos = [];

        foreach (MetodoPago::cases() as $metodo) {
            $campos[] = TextInput::make('medios_declarados.'.$metodo->value)
                ->label($metodo->etiqueta().' contado')
                ->numeric()
                ->minValue(0)
                ->required();
        }

        return Action::make('cerrarTurnoAjeno')
            ->label('Cerrar turno de otro')
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn (): bool => (bool) self::usuarioOpcional()?->can(Permisos::CERRAR_AJENO))
            ->schema([
                Select::make('caja_sesion_id')
                    ->label('Turno abierto')
                    ->options(function (): array {
                        $usuario = self::usuarioOpcional();

                        return CajaSesion::query()
                            ->with(['usuario', 'sucursal'])
                            ->where('estado_sesion', EstadoCajaSesion::Abierta)
                            ->when($usuario instanceof TenantUser, fn ($query) => $query->where('user_id', '!=', $usuario->id))
                            ->orderByDesc('id')
                            ->get()
                            ->filter(function (CajaSesion $sesion) use ($usuario): bool {
                                return $usuario instanceof TenantUser
                                    && app(AccesoSucursales::class)->autoriza($usuario, (int) $sesion->sucursal_id);
                            })
                            ->mapWithKeys(fn (CajaSesion $sesion): array => [
                                $sesion->id => ($sesion->usuario?->name ?? 'Vendedor').' · '.($sesion->sucursal?->nombre ?? 'Sucursal').' · turno '.$sesion->id,
                            ])
                            ->all();
                    })
                    ->required()
                    ->native(false),
                ...$campos,
                Textarea::make('justificacion_diferencia')->label('Justificación si no calza')->rows(2),
                Textarea::make('cierre_vales_motivo')->label('Motivo si hay vales pendientes')->rows(2),
                Select::make('seguimiento_user_id')
                    ->label('Seguimiento de vales')
                    ->options(fn (): array => TenantUser::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $sesion = CajaSesion::query()->findOrFail($data['caja_sesion_id']);
                app(CerrarCajaSesion::class)->cerrar($sesion, $data, self::usuario());
                Notification::make()->title('Turno cerrado')->success()->send();
            });
    }

    private static function adjuntar(): Action
    {
        return Action::make('adjuntarComprobante')
            ->label('Adjuntar comprobante')
            ->icon(Heroicon::OutlinedPaperClip)
            ->visible(fn (): bool => self::puedeGestionarRendiciones())
            ->schema([
                Select::make('movimiento_id')->label('Gasto pendiente')->options(fn (): array => app(AutorizarOrigen::class)->movimientosParaRendicion(self::usuario())
                    ->where('estado', EstadoCajaChicaMovimiento::PendienteComprobante)
                    ->orderByDesc('id')
                    ->pluck('concepto', 'id')
                    ->all())->required()->native(false),
                Select::make('tipo_comprobante')->label('Tipo')->options(self::tiposComprobante())->native(false),
                TextInput::make('numero_comprobante')->label('Número'),
                self::comprobanteCajaChica('Archivo'),
            ])
            ->action(function (array $data): void {
                $movimiento = CajaChicaMovimiento::query()->findOrFail($data['movimiento_id']);
                app(AdjuntarComprobante::class)->adjuntar($movimiento, $data, self::usuario());
                Notification::make()->title('Comprobante guardado')->success()->send();
            });
    }

    private static function corregir(): Action
    {
        return Action::make('corregirMovimiento')
            ->label('Corregir registro')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (): bool => (bool) self::usuarioOpcional()?->can(Permisos::REVISAR))
            ->schema([
                Select::make('movimiento_id')->label('Movimiento')->options(fn (): array => CajaChicaMovimiento::query()->where('tipo', '!=', TipoCajaChicaMovimiento::Correccion)->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn (CajaChicaMovimiento $movimiento): array => [$movimiento->id => $movimiento->tipo->etiqueta().' · '.$movimiento->monto.' · '.$movimiento->concepto])->all())->required()->native(false),
                Select::make('clase')->label('Qué pasó')->options(['error' => 'Error de registro', 'devolucion' => 'Devolución real de efectivo'])->required()->native(false),
                Textarea::make('motivo')->label('Motivo')->required(),
                Select::make('destino_fondo_id')->label('Fondo que recibe la devolución')->options(self::fondos())->native(false),
                Select::make('destino_caja_sesion_id')->label('O turno que la recibe')->options(self::turnosAbiertos())->native(false),
            ])
            ->action(function (array $data): void {
                $movimiento = CajaChicaMovimiento::query()->findOrFail($data['movimiento_id']);
                app(CorregirMovimiento::class)->corregir($movimiento, $data, self::usuario());
                Notification::make()->title('Corrección registrada')->success()->send();
            });
    }

    /**
     * @return list<Select>
     */
    private static function origen(bool $soloTurno): array
    {
        if ($soloTurno) {
            return [
                Select::make('caja_sesion_id')
                    ->label('Cajón')
                    ->options(fn (): array => self::turnosAbiertos())
                    ->default(fn (): ?int => CajaSesion::query()->where('user_id', auth('tenant')->id())->where('estado_sesion', EstadoCajaSesion::Abierta)->value('id'))
                    ->required()
                    ->native(false)
                    ->helperText('Se muestra el vendedor, la sucursal y el turno.'),
            ];
        }

        $fondos = CajaChicaFondo::query()->with('sucursal')->where('responsable_id', self::usuario()->id)
            ->orderBy('id')->get()
            ->mapWithKeys(fn (CajaChicaFondo $fondo): array => [$fondo->id => $fondo->sucursal?->nombre.' · saldo '.$fondo->saldo_actual])
            ->all();

        return [
            Select::make('fondo_id')->label('Fondo')->options($fondos)
                ->default(count($fondos) === 1 ? array_key_first($fondos) : null)->required()->native(false),
        ];
    }

    private static function tieneFondoPropio(): bool
    {
        return CajaChicaFondo::query()->where('responsable_id', self::usuarioOpcional()?->id)->exists();
    }

    private static function puedeGestionarRendiciones(): bool
    {
        $usuario = self::usuarioOpcional();

        return $usuario instanceof TenantUser && ($usuario->can(Permisos::PROPIA)
            || $usuario->can(Permisos::AJENA) || $usuario->can(Permisos::OPERAR) || $usuario->can(Permisos::REVISAR));
    }

    private static function puedeGastar(bool $soloTurno): bool
    {
        $usuario = self::usuarioOpcional();

        if (! $usuario instanceof TenantUser) {
            return false;
        }

        if ($soloTurno) {
            return (bool) NegocioConfiguracion::query()->value('permite_gastos_cajon_venta')
                && ($usuario->can(Permisos::PROPIA) || $usuario->can(Permisos::AJENA));
        }

        return $usuario->can(Permisos::OPERAR);
    }

    /**
     * @return array<int, string>
     */
    private static function turnosAbiertos(): array
    {
        $usuario = self::usuarioOpcional();

        return CajaSesion::query()
            ->with(['usuario', 'sucursal'])
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->orderByDesc('fecha_apertura')
            ->get()
            ->filter(function (CajaSesion $sesion) use ($usuario): bool {
                if (! $usuario instanceof TenantUser) {
                    return false;
                }

                if ((int) $sesion->user_id === (int) $usuario->id) {
                    return true;
                }

                return $usuario->can(Permisos::AJENA)
                    && app(AccesoSucursales::class)->autoriza($usuario, (int) $sesion->sucursal_id);
            })
            ->mapWithKeys(fn (CajaSesion $sesion): array => [
                $sesion->id => ($sesion->usuario?->name ?? 'Vendedor').' · '.($sesion->sucursal?->nombre ?? 'Sucursal').' · turno '.$sesion->id,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function fondos(): array
    {
        return CajaChicaFondo::query()->with('sucursal')->orderBy('id')->get()
            ->mapWithKeys(fn (CajaChicaFondo $fondo): array => [$fondo->id => ($fondo->sucursal?->nombre ?? 'Sucursal').' · saldo '.$fondo->saldo_actual])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function categorias(): array
    {
        return CategoriaGasto::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private static function compras(): array
    {
        return OrdenCompra::query()->orderByDesc('id')->limit(30)->pluck('id', 'id')->map(fn (mixed $id): string => 'Compra '.$id)->all();
    }

    /**
     * @return array<string, string>
     */
    private static function tiposComprobante(): array
    {
        $opciones = [];

        foreach (TipoComprobanteCajaChica::cases() as $tipo) {
            $opciones[$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }

    /**
     * @return array<int, string>
     */
    private static function sucursalesDelUsuario(): array
    {
        $usuario = self::usuarioOpcional();

        if (! $usuario instanceof TenantUser) {
            return [];
        }

        $ids = app(AccesoSucursales::class)->ids($usuario);

        return Sucursal::query()->whereIn('id', $ids)->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    private static function usuario(): TenantUser
    {
        $usuario = self::usuarioOpcional();

        if (! $usuario instanceof TenantUser) {
            throw ValidationException::withMessages(['origen' => 'Tenés que entrar al negocio.']);
        }

        return $usuario;
    }

    private static function comprobanteCajaChica(string $etiqueta): FileUpload
    {
        return FileUpload::make('comprobante_archivo_path')
            ->label($etiqueta)
            ->directory(fn (): string => app(ArchivosNegocio::class)->directorio(TipoArchivoNegocio::ComprobanteCajaChica))
            ->maxSize(NegocioConfiguracion::COMPROBANTE_MAX_KB);
    }

    private static function usuarioOpcional(): ?TenantUser
    {
        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser ? $usuario : null;
    }
}
