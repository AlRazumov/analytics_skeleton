<?php

use App\Core\Domain\Product;

it('constructs with given values', function () {
    $product = new Product(id: 'p1', name: 'Widget', category: 'tools', meta: ['sku' => 'W1']);

    expect($product->id)->toBe('p1')
        ->and($product->name)->toBe('Widget')
        ->and($product->category)->toBe('tools')
        ->and($product->meta)->toBe(['sku' => 'W1']);
});

it('defaults category to null and meta to empty array', function () {
    $product = new Product(id: 'p1', name: 'Widget');

    expect($product->category)->toBeNull()
        ->and($product->meta)->toBe([]);
});

it('is immutable', function () {
    $product = new Product(id: 'p1', name: 'Widget');

    expect(fn () => $product->name = 'Changed')->toThrow(Error::class);
});
