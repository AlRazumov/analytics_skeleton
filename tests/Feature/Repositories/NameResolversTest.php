<?php

use App\Models\Staging\StagingProduct;
use App\Models\Staging\StagingWarehouse;
use App\Repositories\DbProductNameResolver;
use App\Repositories\DbWarehouseNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Полная CSV-выгрузка передаёт весь справочник: у Postgres не больше
// 65535 параметров в запросе, поэтому резолверы ищут порциями.
it('resolves names for more ids than Postgres accepts in one query', function () {
    StagingProduct::create(['external_id' => 'p-69999', 'name' => 'Последний', 'synced_at' => now()]);
    StagingWarehouse::create(['external_id' => 'w-0', 'name' => 'Первый', 'synced_at' => now()]);
    $ids = fn (string $prefix) => array_map(fn ($i) => "{$prefix}-{$i}", range(0, 69999));

    expect((new DbProductNameResolver)->names($ids('p')))->toBe(['p-69999' => 'Последний'])
        ->and((new DbWarehouseNameResolver)->names($ids('w')))->toBe(['w-0' => 'Первый']);
});
