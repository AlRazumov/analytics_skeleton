<?php

use App\Core\Domain\Deal;

it('constructs with given values', function () {
    $date = new DateTimeImmutable('2026-01-01');
    $deal = new Deal(id: '1', productId: 'p1', amount: 100.5, date: $date, meta: ['foo' => 'bar']);

    expect($deal->id)->toBe('1')
        ->and($deal->productId)->toBe('p1')
        ->and($deal->amount)->toBe(100.5)
        ->and($deal->date)->toBe($date)
        ->and($deal->meta)->toBe(['foo' => 'bar']);
});

it('defaults meta to empty array', function () {
    $deal = new Deal(id: '1', productId: 'p1', amount: 1.0, date: new DateTimeImmutable);

    expect($deal->meta)->toBe([]);
});

it('is immutable', function () {
    $deal = new Deal(id: '1', productId: 'p1', amount: 1.0, date: new DateTimeImmutable);

    expect(fn () => $deal->amount = 2.0)->toThrow(Error::class);
});
