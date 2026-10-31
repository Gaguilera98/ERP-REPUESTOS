<?php

namespace App\Filament\Negocio\Pages;

use App\Enums\ModoMoneda;
use App\Enums\Moneda;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
            'descuento_max_porcentaje' => $configuracion->descuento_max_porcentaje,
            'descuento_max_importe' => $configuracion->descuento_max_importe,
            'moneda' => $configuracion->moneda->value,
            'modo_moneda' => $configuracion->modo_moneda->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                TextInput::make('descuento_max_importe')
                    ->label('Descuento máximo en importe')
                    ->helperText('En 0, un importe fijo lo autoriza el supervisor.')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Select::make('moneda')
                    ->label('Moneda de la tienda')
                    ->options(collect(Moneda::cases())->mapWithKeys(
                        fn (Moneda $moneda): array => [$moneda->value => $moneda->etiqueta()],
                    )->all())
                    ->required()
                    ->native(false),
                Select::make('modo_moneda')
                    ->label('Dólar')
                    ->helperText('El dólar es referencia. No se cobra en dólares.')
                    ->options(collect(ModoMoneda::cases())->mapWithKeys(
                        fn (ModoMoneda $modo): array => [$modo->value => $modo->etiqueta()],
                    )->all())
                    ->required()
                    ->native(false),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $configuracion = NegocioConfiguracion::query()->firstOrFail();
        $user = auth()->user();

        abort_unless($user instanceof TenantUser && $user->can('update', $configuracion), 403);

        $configuracion->update($this->form->getState());

        Notification::make()
            ->title('Configuración guardada')
            ->success()
            ->send();
    }
}
