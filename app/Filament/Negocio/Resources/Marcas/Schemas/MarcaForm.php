<?php

namespace App\Filament\Negocio\Resources\Marcas\Schemas;

use App\Models\Marca;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MarcaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::nombre(),
                Toggle::make('is_active')
                    ->label('Activa')
                    ->default(true)
                    ->helperText('Apagada no sale al crear un producto. Los que ya la usan la conservan.'),
            ]);
    }

    public static function nombre(): TextInput
    {
        return TextInput::make('nombre')
            ->label('Nombre')
            ->placeholder('Ej: Toyota, SKF')
            ->required()
            ->maxLength(255)
            ->dehydrateStateUsing(fn (string $state): string => trim($state))
            ->rules([
                fn (TextInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                    $nombre = trim((string) $value);
                    $record = $component->getRecord();
                    $marcaId = $record instanceof Marca ? $record->getKey() : null;

                    $existe = Marca::query()
                        ->whereRaw('lower(nombre) = lower(?)', [$nombre])
                        ->when($marcaId, fn ($query) => $query->whereKeyNot($marcaId))
                        ->exists();

                    if ($existe) {
                        $fail('Ya existe una marca con ese nombre.');
                    }
                },
            ]);
    }
}
