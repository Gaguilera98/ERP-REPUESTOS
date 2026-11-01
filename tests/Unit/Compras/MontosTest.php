<?php

use App\Compras\Montos;

it('oculta ceros a la derecha al mostrar una cantidad', function () {
    expect(Montos::sinCeros('2.0000'))->toBe('2')
        ->and(Montos::sinCeros('0.7000'))->toBe('0.7')
        ->and(Montos::sinCeros('0.5'))->toBe('0.5')
        ->and(Montos::sinCeros('1.5000'))->toBe('1.5')
        ->and(Montos::sinCeros('0.0000'))->toBe('0');
});
