<?php

use App\Support\EnvNumber;

/** @param array<string, string|null> $vars */
function withEnv(array $vars, Closure $fn): mixed
{
    foreach ($vars as $k => $v) {
        $v === null ? putenv($k) : putenv("{$k}={$v}");
        if ($v === null) {
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }
    try {
        return $fn();
    } finally {
        foreach (array_keys($vars) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }
}

it('returns the default when the variable is missing or empty', function () {
    expect(withEnv(['T_ENV_NUM' => null], fn () => EnvNumber::int('T_ENV_NUM', 7)))->toBe(7)
        ->and(withEnv(['T_ENV_NUM' => ''], fn () => EnvNumber::int('T_ENV_NUM', 7)))->toBe(7)
        ->and(withEnv(['T_ENV_NUM' => null], fn () => EnvNumber::floatList('T_ENV_NUM', [1.0])))->toBe([1.0]);
});

it('parses integers and lists', function () {
    expect(withEnv(['T_ENV_NUM' => ' 25 '], fn () => EnvNumber::int('T_ENV_NUM', 7)))->toBe(25)
        ->and(withEnv(['T_ENV_NUM' => '10, 20,30'], fn () => EnvNumber::intList('T_ENV_NUM', [])))->toBe([10, 20, 30])
        ->and(withEnv(['T_ENV_NUM' => '0.5,1,2.5'], fn () => EnvNumber::floatList('T_ENV_NUM', [])))->toBe([0.5, 1.0, 2.5]);
});

it('rejects garbage and out-of-range values with the variable name', function (string $value, Closure $read) {
    expect(fn () => withEnv(['T_ENV_NUM' => $value], $read))->toThrow(InvalidArgumentException::class, 'T_ENV_NUM');
})->with([
    'not a number' => ['abc', fn () => EnvNumber::int('T_ENV_NUM', 1)],
    'fraction for int' => ['1.5', fn () => EnvNumber::int('T_ENV_NUM', 1)],
    'boolean word' => ['true', fn () => EnvNumber::int('T_ENV_NUM', 1)],
    'below min' => ['0', fn () => EnvNumber::int('T_ENV_NUM', 1, 1)],
    'above max' => ['1001', fn () => EnvNumber::int('T_ENV_NUM', 1, 1, 1000)],
    'bad list item' => ['8,x,31', fn () => EnvNumber::intList('T_ENV_NUM', [])],
    'empty list item' => ['8,,31', fn () => EnvNumber::intList('T_ENV_NUM', [])],
    'negative float' => ['-1', fn () => EnvNumber::floatList('T_ENV_NUM', [])],
]);

it('feeds config/analytics.php: thresholds from the environment, defaults otherwise', function () {
    $config = withEnv([
        'ANALYTICS_TRANSFER_STOCK_SURPLUS_MIN_STOCK' => '50',
        'ANALYTICS_DEAD_STOCK_DAYS' => '120',
        'ANALYTICS_DISPLAY_DAYS_OF_STOCK_BOUNDS' => '7,30',
    ], fn () => require __DIR__.'/../../../config/analytics.php');

    expect($config['transfers']['stock_surplus_min_stock'])->toBe(50)
        ->and($config['stock']['dead_stock_days'])->toBe(120)
        // Порог показа неликвида по умолчанию следует порогу расчёта.
        ->and($config['display']['dead_stock_display_days'])->toBe(120)
        ->and($config['display']['days_of_stock_bounds'])->toBe([7, 30])
        ->and($config['transfers']['deficit_days'])->toBe(14)
        ->and($config['display']['turnover_bounds'])->toBe([1.0, 2.0]);
});
