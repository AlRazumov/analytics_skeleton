<?php

use App\Support\MetricLabels;

it('translates known metric keys and column names', function () {
    expect(MetricLabels::label('revenue'))->toBe('Выручка')
        ->and(MetricLabels::label('turnover'))->toBe('Оборачиваемость')
        ->and(MetricLabels::label('days_of_stock'))->toBe('Дней до обнуления')
        ->and(MetricLabels::label('days_since_last_sale'))->toBe('Дней без продаж')
        ->and(MetricLabels::label('period'))->toBe('Период');
});

it('falls back to the key for an unknown metric and leaves ready-made titles as they are', function () {
    expect(MetricLabels::label('some_new_metric'))->toBe('some_new_metric')
        ->and(MetricLabels::label('Топ товаров по выручке'))->toBe('Топ товаров по выручке')
        ->and(MetricLabels::label(''))->toBe('');
});
