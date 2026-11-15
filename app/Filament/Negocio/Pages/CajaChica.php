<?php

namespace App\Filament\Negocio\Pages;

use App\CajaChica\Permisos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\TipoCajaChicaMovimiento;
use App\Filament\Negocio\CajaChica\AccionesCajaChica;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use UnitEnum;

class CajaChica extends Page implements HasTable
{
    use InteractsWithTable;

    #[Locked]
    public ?int $fondoHistorialId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Caja chica';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Caja chica';

    protected static ?string $slug = 'caja-chica';

    protected string $view = 'filament.negocio.pages.caja-chica';

    public static function canAccess(): bool
    {
        $usuario = auth('tenant')->user();

        return tenancy()->initialized
            && $usuario instanceof TenantUser
            && $usuario->can(Permisos::VER);
    }

    protected function getHeaderActions(): array
    {
        return AccionesCajaChica::delFondo();
    }

    /**
     * @return list<CajaChicaFondo>
     */
    public function fondos(): array
    {
        return $this->fondosVisibles()
            ->with([
                'sucursal',
                'responsable',
                'movimientos' => fn ($query) => $query->latest('id')->limit(5),
                'valesPendientes' => fn ($query) => $query->latest('id'),
                'arqueos' => fn ($query) => $query->latest('id'),
            ])
            ->orderBy('sucursal_id')->get()->all();
    }

    public function verHistorial(int $fondoId): void
    {
        $this->fondoHistorialId = $this->fondosVisibles()->findOrFail($fondoId)->id;
        $this->resetTable();
        $this->dispatch('open-modal', id: 'historial-caja-chica');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(AccesoSucursales::class)->filtrarSucursal(CajaChicaMovimiento::query(), auth('tenant')->user())
                ->where('fondo_id', $this->fondoHistorialId ?? 0)
                ->whereIn('fondo_id', $this->fondosVisibles()->select('id')))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('concepto')->label('Concepto')->wrap(),
                TextColumn::make('tipo')->label('Tipo')->formatStateUsing(fn (TipoCajaChicaMovimiento $state): string => $state->etiqueta()),
                TextColumn::make('estado')->label('Estado')->badge()->formatStateUsing(fn (EstadoCajaChicaMovimiento $state): string => $state->etiqueta()),
                TextColumn::make('monto')->label('Efectivo')->alignEnd()
                    ->formatStateUsing(fn (CajaChicaMovimiento $record): string => $record->importeVisible(NegocioConfiguracion::codigoMoneda())),
            ])
            ->filters([
                Filter::make('fecha')->schema([DatePicker::make('dia')->label('Día')->native(false)])
                    ->query(function (Builder $query, array $data): Builder {
                        $dia = $data['dia'] ?? null;

                        if (blank($dia)) {
                            return $query;
                        }

                        return validator(['dia' => $dia], ['dia' => ['date_format:Y-m-d']])->passes()
                            ? $query->whereDate('created_at', $dia)
                            : $query->whereKey(0);
                    }),
                SelectFilter::make('tipo')->label('Tipo')->options(collect(TipoCajaChicaMovimiento::cases())->mapWithKeys(fn ($tipo): array => [$tipo->value => $tipo->etiqueta()])->all()),
                SelectFilter::make('estado')->label('Estado')->options(collect(EstadoCajaChicaMovimiento::cases())->mapWithKeys(fn ($estado): array => [$estado->value => $estado->etiqueta()])->all()),
            ])
            ->recordActions([
                $this->accionDetalleVale('verValeHistorial')
                    ->visible(fn (CajaChicaMovimiento $record): bool => $record->valeRelacionadoId() !== null)
                    ->modalContent(fn (CajaChicaMovimiento $record): View => $this->detalleVale($record->valeRelacionadoId() ?? 0)),
            ]);
    }

    public function verValeAction(): Action
    {
        return $this->accionDetalleVale('verVale')
            ->modalContent(fn (array $arguments): View => $this->detalleVale((int) ($arguments['valeId'] ?? 0)));
    }

    private function accionDetalleVale(string $nombre): Action
    {
        return Action::make($nombre)->label('Ver vale')->icon(Heroicon::OutlinedEye)
            ->modalHeading('Detalle del vale')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar');
    }

    private function detalleVale(int $valeId): View
    {
        $usuario = auth('tenant')->user();
        abort_unless(static::canAccess() && $usuario instanceof TenantUser, 403);

        $sucursales = $usuario->can(Permisos::REVISAR) ? app(AccesoSucursales::class)->ids($usuario) : [];
        $vale = app(AccesoSucursales::class)->filtrarSucursal(CajaChicaMovimiento::query(), $usuario)->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('fondo_id', $this->fondosVisibles()->select('id'))
                ->orWhereHas('sesion', fn (Builder $sesion): Builder => $sesion->where('user_id', $usuario->id)->orWhereIn('sucursal_id', $sucursales)))
            ->with(['registradoPor', 'rendidoPor', 'sucursal', 'categoria'])
            ->find($valeId);

        return view('filament.negocio.pages.caja-chica-vale', [
            'vale' => $vale,
            'sobrante' => $vale?->movimientosDerivados()->where('tipo', TipoCajaChicaMovimiento::IngresoSobrante)->first(),
            'moneda' => NegocioConfiguracion::codigoMoneda(),
        ]);
    }

    private function fondosVisibles(): Builder
    {
        $usuario = auth('tenant')->user();

        abort_unless(static::canAccess() && $usuario instanceof TenantUser, 403);

        $sucursales = $usuario->can(Permisos::REVISAR) || $usuario->can(Permisos::FONDOS)
            ? app(AccesoSucursales::class)->ids($usuario)
            : [];

        return app(AccesoSucursales::class)->filtrarSucursal(CajaChicaFondo::query(), $usuario)
            ->where(fn (Builder $query): Builder => $query->where('responsable_id', $usuario->id)
                ->orWhereIn('sucursal_id', $sucursales));
    }
}
