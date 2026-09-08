<?php

use App\Core\Staging\StagingDeal;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a staging product', function () {
    $product = StagingProduct::create([
        'external_id' => 'ext-1',
        'name' => 'Widget',
        'category' => 'tools',
        'meta' => ['sku' => 'W1'],
        'synced_at' => now(),
    ]);

    expect($product->exists)->toBeTrue();
    $this->assertDatabaseHas('staging_products', ['external_id' => 'ext-1']);
});

it('creates a staging deal', function () {
    $deal = StagingDeal::create([
        'external_id' => 'ext-1',
        'product_external_id' => 'ext-product-1',
        'amount' => 123.45,
        'occurred_at' => now(),
        'meta' => ['source' => 'test'],
        'synced_at' => now(),
    ]);

    expect($deal->exists)->toBeTrue();
    $this->assertDatabaseHas('staging_deals', ['external_id' => 'ext-1']);
});

it('creates a staging stock movement', function () {
    $movement = StagingStockMovement::create([
        'external_id' => 'ext-1',
        'product_external_id' => 'ext-product-1',
        'warehouse_external_id' => 'ext-warehouse-1',
        'quantity' => 10,
        'type' => 'in',
        'occurred_at' => now(),
        'meta' => null,
        'synced_at' => now(),
    ]);

    expect($movement->exists)->toBeTrue();
    $this->assertDatabaseHas('staging_stock_movements', ['external_id' => 'ext-1']);
});
