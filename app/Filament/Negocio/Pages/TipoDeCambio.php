<?php

namespace App\Filament\Negocio\Pages;

use App\Catalogo\PreciosDeReferencia;
use App\Catalogo\PublicarTipoCambio;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Models\TipoCambio;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use UnitEnum;

class TipoDeCambio extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $title = 'Tipo de cambio';

    protected static ?string $slug = 'tipo-de-cambio';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected string $view = 'filament.negocio.pages.tipo-de-cambio';

    public ?string $valor = null;

    /** @var array{firma: string, cantidad: int, conflictos: int, cambios: list<array>, errores: list<array>}|null */
    #[Locked]
    public ?array $vistaPrevia = null;

    public static function canAccess(): bool
    {
        $usuario = auth('tenant')->user();

        return tenancy()->initialized
            && $usuario instanceof TenantUser
            && $usuario->can(PublicarTipoCambio::PERMISO)
            && app(PreciosDeReferencia::class)->habilitado();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('valor')
                ->label('Moneda local por 1 USD')
                ->prefix(NegocioConfiguracion::codigoMoneda())
                ->numeric()
                ->minValue(0.0001)
                ->maxValue(999999.9999)
                ->rule('decimal:0,4')
                ->required(),
        ]);
    }

    public function revisar(PublicarTipoCambio $publicacion): void
    {
        abort_unless(static::canAccess(), 403);

        $this->vistaPrevia = null;
        $this->vistaPrevia = $publicacion->previsualizar($this->form->getState()['valor']);
    }

    public function updatedValor(): void
    {
        $this->vistaPrevia = null;
    }

    public function publicar(PublicarTipoCambio $publicacion): void
    {
        abort_unless(static::canAccess(), 403);
        $firma = $this->vistaPrevia['firma'] ?? null;

        if ($firma === null) {
            throw ValidationException::withMessages(['valor' => 'Revisá los precios antes de publicar.']);
        }

        $publicacion->publicar($this->form->getState()['valor'], $firma, auth('tenant')->user());

        $this->vistaPrevia = null;
        $this->form->fill();
        $this->resetPage('historialCotizaciones');
        Notification::make()->success()->title('Tipo de cambio publicado')->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        $moneda = NegocioConfiguracion::codigoMoneda();

        return [
            'moneda' => $moneda,
            'vigente' => app(PreciosDeReferencia::class)->vigente($moneda),
            'historial' => $this->historial(),
        ];
    }

    private function historial(): Paginator
    {
        return TipoCambio::query()->with('usuario:id,name')->latest('id')->simplePaginate(10, ['*'], 'historialCotizaciones');
    }
}
