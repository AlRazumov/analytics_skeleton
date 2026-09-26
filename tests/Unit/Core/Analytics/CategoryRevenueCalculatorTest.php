<?php

use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Domain\Deal;
use App\Core\Domain\Product;

function categoryDeal(string $id, string $product, float $amount, string $day): Deal
{
    return new Deal($id, $product, $amount, new DateTimeImmutable($day.' 12:00'));
}

it('sums revenue per category and month and counts distinct products sold', function () {
    $products = [new Product('p1', 'P1', 'Игрушки'), new Product('p2', 'P2', 'Игрушки'), new Product('p3', 'P3', 'Дом')];
    $deals = [
        categoryDeal('d1', 'p1', 100, '2026-07-05'),
        categoryDeal('d2', 'p1', 50, '2026-07-06'),
        categoryDeal('d3', 'p2', 30, '2026-07-07'),
        categoryDeal('d4', 'p3', 70, '2026-07-08'),
        categoryDeal('d5', 'p3', 10, '2026-08-01'),
    ];

    $records = collect((new CategoryRevenueCalculator)->calculate($deals, $products))
        ->keyBy(fn ($r) => $r->entityId.'|'.$r->period);

    expect($records)->toHaveCount(3)
        ->and($records['Игрушки|month:2026-07']->value)->toBe(180.0)
        ->and($records['Игрушки|month:2026-07']->valueMeta)->toBe(['products_sold' => 2])
        ->and($records['Дом|month:2026-07']->value)->toBe(70.0)
        ->and($records['Дом|month:2026-08']->value)->toBe(10.0)
        ->and($records->every(fn ($r) => $r->entityType === 'category' && $r->metricKey === 'revenue'))->toBeTrue();
});

it('puts products without a category and unknown products under __none__', function () {
    $products = [new Product('p1', 'P1'), new Product('p2', 'P2', '')];
    $deals = [
        categoryDeal('d1', 'p1', 10, '2026-07-01'),
        categoryDeal('d2', 'p2', 20, '2026-07-02'),
        categoryDeal('d3', 'ghost', 5, '2026-07-03'),
    ];

    $records = (new CategoryRevenueCalculator)->calculate($deals, $products);

    expect($records)->toHaveCount(1)
        ->and($records[0]->entityId)->toBe(CategoryRevenueCalculator::NO_CATEGORY)
        ->and($records[0]->value)->toBe(35.0)
        ->and($records[0]->valueMeta)->toBe(['products_sold' => 3]);
});

it('returns nothing without deals', function () {
    expect((new CategoryRevenueCalculator)->calculate([], [new Product('p1', 'P1', 'Дом')]))->toBe([]);
});
