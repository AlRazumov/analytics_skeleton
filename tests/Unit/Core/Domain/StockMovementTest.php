<?php

use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

it('constructs with given values', function () {
    $date = new DateTimeImmutable('2026-01-01');
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 5.0,
        type: StockMovementType::In,
        date: $date,
        meta: ['note' => 'test'],
    );

    expect($movement->id)->toBe('m1')
        ->and($movement->productId)->toBe('p1')
        ->and($movement->warehouseId)->toBe('w1')
        ->and($movement->quantity)->toBe(5.0)
        ->and($movement->type)->toBe(StockMovementType::In)
        ->and($movement->date)->toBe($date)
        ->and($movement->toWarehouseId)->toBeNull()
        ->and($movement->meta)->toBe(['note' => 'test']);
});

it('defaults meta to empty array', function () {
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::Out,
        date: new DateTimeImmutable(),
    );

    expect($movement->meta)->toBe([]);
});

it('is immutable', function () {
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::Transfer,
        date: new DateTimeImmutable(),
        toWarehouseId: 'w2',
    );

    expect(fn () => $movement->quantity = 2.0)->toThrow(Error::class);
});

it('allows In without toWarehouseId', function () {
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::In,
        date: new DateTimeImmutable(),
    );

    expect($movement->toWarehouseId)->toBeNull();
});

it('allows Out without toWarehouseId', function () {
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::Out,
        date: new DateTimeImmutable(),
    );

    expect($movement->toWarehouseId)->toBeNull();
});

it('throws when Transfer is created without toWarehouseId', function () {
    expect(fn () => new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::Transfer,
        date: new DateTimeImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});

it('throws when In/Out is created with toWarehouseId', function () {
    expect(fn () => new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::In,
        date: new DateTimeImmutable(),
        toWarehouseId: 'w2',
    ))->toThrow(InvalidArgumentException::class);
});

it('allows Transfer with toWarehouseId', function () {
    $movement = new StockMovement(
        id: 'm1',
        productId: 'p1',
        warehouseId: 'w1',
        quantity: 1.0,
        type: StockMovementType::Transfer,
        date: new DateTimeImmutable(),
        toWarehouseId: 'w2',
    );

    expect($movement->warehouseId)->toBe('w1')
        ->and($movement->toWarehouseId)->toBe('w2');
});
