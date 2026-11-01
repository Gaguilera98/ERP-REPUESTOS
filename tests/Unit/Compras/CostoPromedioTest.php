<?php

use App\Compras\CostoPromedio;

it('promedia el costo en cuatro decimales y arranca de cero cuando no hay stock', function () {
    $costo = new CostoPromedio;

    expect($costo->resultante('0', 0, '10.3333', 4))->toBe('10.3333')
        ->and($costo->resultante('10.0000', 4, '20', 4))->toBe('15.0000')
        ->and($costo->resultante('1.0000', 1, '1.0001', 1))->toBe('1.0001');
});
