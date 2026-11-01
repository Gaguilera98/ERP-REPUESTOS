<?php

use App\Compras\MontoEnPalabras;

it('dice el monto en palabras para no confundir el punto con los miles', function () {
    expect(MontoEnPalabras::decir('5000.0000', 'BOB'))->toBe('cinco mil bolivianos')
        ->and(MontoEnPalabras::decir('25', 'BOB'))->toBe('veinticinco bolivianos')
        ->and(MontoEnPalabras::decir('200.0000', 'BOB'))->toBe('doscientos bolivianos')
        ->and(MontoEnPalabras::decir('1', 'BOB'))->toBe('un boliviano')
        ->and(MontoEnPalabras::decir('21', 'BOB'))->toBe('veintiún bolivianos')
        ->and(MontoEnPalabras::decir('0.50', 'BOB'))->toBe('cincuenta centavos')
        ->and(MontoEnPalabras::decir('200.50', 'BOB'))->toBe('doscientos bolivianos con cincuenta centavos')
        ->and(MontoEnPalabras::decir('1.01', 'BOB'))->toBe('un boliviano con un centavo')
        ->and(MontoEnPalabras::decir('0', 'BOB'))->toBe('cero bolivianos')
        ->and(MontoEnPalabras::numerosYLetras('5000.0000', 'BOB'))->toBe('BOB 5000 (cinco mil bolivianos)')
        ->and(MontoEnPalabras::numerosYLetras('200.50', 'BOB'))->toBe('BOB 200,50 (doscientos bolivianos con cincuenta centavos)');
});
