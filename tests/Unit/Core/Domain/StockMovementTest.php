<?php

use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

function movement(StockMovementType $type, float $quantity): StockMovement
{
    return new StockMovement('m1', 'p1', 'w1', $quantity, $type, new DateTimeImmutable('2026-01-01'));
}

it('constructs with given values', function () {
    $date = new DateTimeImmutable('2026-01-01');
    $movement = new StockMovement('m1', 'p1', 'w1', 5.0, StockMovementType::Receipt, $date, ['note' => 'test']);

    expect($movement->id)->toBe('m1')
        ->and($movement->productId)->toBe('p1')
        ->and($movement->warehouseId)->toBe('w1')
        ->and($movement->quantity)->toBe(5.0)
        ->and($movement->type)->toBe(StockMovementType::Receipt)
        ->and($movement->date)->toBe($date)
        ->and($movement->meta)->toBe(['note' => 'test']);
});

it('defaults meta to empty array', function () {
    expect(movement(StockMovementType::Sale, -1.0)->meta)->toBe([]);
});

it('is immutable', function () {
    expect(fn () => movement(StockMovementType::Sale, -1.0)->quantity = 2.0)->toThrow(Error::class);
});

it('accepts a quantity sign consistent with the type', function (StockMovementType $type, float $quantity) {
    expect(movement($type, $quantity)->quantity)->toBe($quantity);
})->with([
    [StockMovementType::Receipt, 3.0],
    [StockMovementType::TransferIn, 3.0],
    [StockMovementType::Sale, -3.0],
    [StockMovementType::TransferOut, -3.0],
    [StockMovementType::Writeoff, -3.0],
    [StockMovementType::Adjustment, 3.0],
    [StockMovementType::Adjustment, -3.0],
]);

it('rejects a quantity sign inconsistent with the type', function (StockMovementType $type, float $quantity) {
    movement($type, $quantity);
})->with([
    [StockMovementType::Receipt, -3.0],
    [StockMovementType::TransferIn, -3.0],
    [StockMovementType::Sale, 3.0],
    [StockMovementType::TransferOut, 3.0],
    [StockMovementType::Writeoff, 3.0],
])->throws(InvalidArgumentException::class);

it('rejects zero quantity', function () {
    movement(StockMovementType::Adjustment, 0.0);
})->throws(InvalidArgumentException::class);
