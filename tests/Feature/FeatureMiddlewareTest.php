<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_t/one', fn () => 'ok')->middleware('feature:dead_stock');
    Route::get('/_t/any', fn () => 'ok')->middleware('feature:dead_stock,stockout_risk');
});

it('returns 404 when the only listed flag is off and passes when on', function () {
    config(['analytics.features.dead_stock' => true]);
    $this->get('/_t/one')->assertOk();

    config(['analytics.features.dead_stock' => false]);
    $this->get('/_t/one')->assertNotFound();
});

it('passes when at least one of several flags is on', function () {
    config(['analytics.features.dead_stock' => false, 'analytics.features.stockout_risk' => true]);
    $this->get('/_t/any')->assertOk();

    config(['analytics.features.stockout_risk' => false]);
    $this->get('/_t/any')->assertNotFound();
});

it('treats an unknown flag as off', function () {
    Route::get('/_t/unknown', fn () => 'ok')->middleware('feature:no_such_flag');

    $this->get('/_t/unknown')->assertNotFound();
});

it('has all flags on by default', function () {
    expect(config('analytics.features'))->toBe(['dead_stock' => true, 'stockout_risk' => true, 'top_products' => true, 'turnover' => true]);
});
