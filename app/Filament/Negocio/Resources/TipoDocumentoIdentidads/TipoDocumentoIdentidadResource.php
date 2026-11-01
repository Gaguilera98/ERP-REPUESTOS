<?php

namespace App\Filament\Negocio\Resources\TipoDocumentoIdentidads;

use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Pages\CreateTipoDocumentoIdentidad;
use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Pages\EditTipoDocumentoIdentidad;
use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Pages\ListTipoDocumentoIdentidads;
use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Schemas\TipoDocumentoIdentidadForm;
use App\Filament\Tables\OrdenRegistro;
use App\Models\TipoDocumentoIdentidad;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TipoDocumentoIdentidadResource extends Resource
{
    protected static ?string $model = TipoDocumentoIdentidad::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'tipos-documento';

    protected static ?string $modelLabel = 'tipo de documento';

    protected static ?string $pluralModelLabel = 'tipos de documento';

    protected static ?string $navigationLabel = 'Tipos de documento';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return TipoDocumentoIdentidadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->modifyQueryUsing(fn ($query) => $query->withCount('atributos'))
            ->columns([
                TextColumn::make('orden')
                    ->label('#')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TipoDocumentoIdentidad $record): string => $record->atributos_count.' campo(s)'),
                IconColumn::make('para_persona_natural')
                    ->label('Persona')
                    ->boolean(),
                IconColumn::make('para_empresa')
                    ->label('Empresa')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('orden')
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
            ->emptyStateHeading('No hay tipos de documento')
            ->emptyStateDescription('Al crear el negocio se cargan Cédula y NIT. Acá podés sumar licencia u otros.')
            ->emptyStateIcon(Heroicon::OutlinedIdentification));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTipoDocumentoIdentidads::route('/'),
            'create' => CreateTipoDocumentoIdentidad::route('/create'),
            'edit' => EditTipoDocumentoIdentidad::route('/{record}/edit'),
        ];
    }
}
