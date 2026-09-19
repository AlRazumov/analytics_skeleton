<?php

namespace App\Repositories;

use App\Core\Staging\StagingWarehouse;
use App\Core\Widgets\Contracts\WarehouseNameResolver;

/** Названия складов из справочника в БД: один запрос whereIn на набор id. */
final class DbWarehouseNameResolver implements WarehouseNameResolver
{
    public function names(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        return StagingWarehouse::query()
            ->whereIn('external_id', array_values(array_unique($warehouseIds)))
            ->pluck('name', 'external_id')
            ->all();
    }
}
