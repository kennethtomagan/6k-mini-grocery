<?php

use App\Support\Money;

it('converts amounts to centavos', function (mixed $input, int $expected) {
    expect(Money::toCents($input))->toBe($expected);
})->with([
    'plain decimal string' => ['12.50', 1250],
    'nineteen ninety-nine' => ['19.99', 1999],
    'single centavo' => ['0.07', 7],
    'whole integer' => [75, 7500],
    'float rounding noise' => [0.1 + 0.2, 30],
    'thousands separator' => ['1,250.00', 125000],
    'empty string' => ['', 0],
    'null' => [null, 0],
    'non numeric text' => ['abc', 0],
    'negative amount' => ['-12.50', -1250],
    'surrounding whitespace' => [' 12.50 ', 1250],
    'single decimal digit' => ['500.5', 50050],
    'largest supported amount' => ['99999999.99', 9999999999],
    'exponent overflow' => ['1e20', 0],
    'infinity' => ['INF', 0],
    'half rounds up at 1.005' => ['1.005', 101],
    'half rounds up at 2.675' => ['2.675', 268],
    'half rounds up at 12.345' => ['12.345', 1235],
]);

it('converts centavos back to a decimal string', function () {
    expect(Money::fromCents(1250))->toBe('12.50')
        ->and(Money::fromCents(7))->toBe('0.07')
        ->and(Money::fromCents(0))->toBe('0.00')
        ->and(Money::fromCents(-1250))->toBe('-12.50')
        ->and(Money::fromCents(9999999999))->toBe('99999999.99');
});

it('formats centavos as pesos', function () {
    expect(Money::format(123450))->toBe('₱1,234.50')
        ->and(Money::format(-1250))->toBe('-₱12.50');
});
