<?php

use App\Ventas\NombreEnPantalla;

it('muestra cada palabra del nombre con mayúscula inicial', function () {
    expect(NombreEnPantalla::titulo('CARBURADOR TOY. 5K COROLLA 83'))->toBe('Carburador Toy. 5k Corolla 83')
        ->and(NombreEnPantalla::titulo('BALINERA/RODAMIENTO UNIVERSAL (12.7X28X8)'))->toBe('Balinera/Rodamiento Universal (12.7x28x8)')
        ->and(NombreEnPantalla::titulo(''))->toBe('—');
});
