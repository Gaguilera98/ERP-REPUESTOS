<?php

namespace App\Filament;

use Filament\Support\Colors\Color;

class Paleta
{
    public const Bosque = '#174C3A';

    public const Verde = '#2E7D63';

    public const Ambar = '#E99543';

    public const Pizarra = '#263746';

    public const Humo = '#F5F7F5';

    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return [
            'primary' => Color::generateV3Palette(self::Bosque),
            'success' => Color::generateV3Palette(self::Verde),
            'warning' => Color::generateV3Palette(self::Ambar),
            'info' => Color::generateV3Palette(self::Pizarra),
            'gray' => [
                50 => self::Humo,
                100 => '#E7EBE8',
                200 => '#D5DDD8',
                300 => '#B7C3C8',
                400 => '#8A9AA3',
                500 => '#5C7080',
                600 => '#3E5260',
                700 => '#314556',
                800 => '#2C4050',
                900 => self::Pizarra,
                950 => '#17232C',
            ],
        ];
    }
}
