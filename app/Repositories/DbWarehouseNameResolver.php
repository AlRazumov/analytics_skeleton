<?php

namespace App\Repositories;

use App\Core\Staging\StagingWarehouse;
use App\Core\Widgets\Contracts\WarehouseNameResolver;

/**
 * Названия складов из справочника в БД: запрос whereIn на набор id,
 * порциями по CHUNK (у Postgres не больше 65535 параметров в запросе —
 * полная CSV-выгрузка может передать весь справочник).
 */
final class DbWarehouseNameResolver implements WarehouseNameResolver
{
    private const int CHUNK = 10000;

    public function names(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        $names = [];
        foreach (array_chunk(array_values(array_unique($warehouseIds)), self::CHUNK) as $chunk) {
            $names += StagingWarehouse::query()
                ->whereIn('external_id', $chunk)
                ->pluck('name', 'external_id')
                ->all();
        }

        return $names;
    }
}
