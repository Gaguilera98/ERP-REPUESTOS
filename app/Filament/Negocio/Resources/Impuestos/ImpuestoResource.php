<?php

namespace App\Filament\Negocio\Resources\Impuestos;

use App\Filament\Negocio\Resources\Impuestos\Pages\ManageImpuestos;
use App\Filament\Tables\OrdenRegistro;
use App\Models\Impuesto;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ImpuestoResource extends Resource
{
    protected static ?string $model = Impuesto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'impuestos';

    protected static ?string $modelLabel = 'impuesto';

    protected static ?string $pluralModelLabel = 'impuestos';

    protected static ?string $navigationLabel = 'Impuestos';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (string $state): string => trim($state))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey()),
                    ),
                TextInput::make('porcentaje')
                    ->label('Porcentaje')
                    ->suffix('%')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required()
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                                $fail('El porcentaje tiene que ser un número de cero a cien.');
                            }
                        },
                    ]),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true)
                    ->helperText('Apagado no se ofrece al vender. Las ventas viejas conservan su copia.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('porcentaje')
                    ->label('Porcentaje')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 2),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay impuestos')
            ->emptyStateDescription('Cargá el IVA u otro impuesto vigente. En la venta se copia el porcentaje.')
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageImpuestos::route('/'),
        ];
    }
}
