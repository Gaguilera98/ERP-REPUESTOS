<?php

namespace App\Filament\Negocio\Resources\TenantUsers;

use App\Filament\Negocio\Resources\TenantUsers\Pages\CreateTenantUser;
use App\Filament\Negocio\Resources\TenantUsers\Pages\EditTenantUser;
use App\Filament\Negocio\Resources\TenantUsers\Pages\ListTenantUsers;
use App\Filament\Negocio\Resources\TenantUsers\Schemas\TenantUserForm;
use App\Filament\Negocio\Resources\TenantUsers\Tables\TenantUsersTable;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantUserResource extends Resource
{
    protected static ?string $model = TenantUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'usuarios';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function form(Schema $schema): Schema
    {
        return TenantUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantUsers::route('/'),
            'create' => CreateTenantUser::route('/create'),
            'edit' => EditTenantUser::route('/{record}/edit'),
        ];
    }
}
