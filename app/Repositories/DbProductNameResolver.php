<?php

namespace App\Repositories;

use App\Core\Staging\StagingProduct;
use App\Core\Widgets\Contracts\ProductNameResolver;

/**
 * Названия товаров из справочника в БД: запрос whereIn на набор id,
 * порциями по CHUNK (у Postgres не больше 65535 параметров в запросе —
 * полная CSV-выгрузка может передать весь справочник).
 */
final class DbProductNameResolver implements ProductNameResolver
{
    private const int CHUNK = 10000;

    public function names(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $names = [];
        foreach (array_chunk(array_values(array_unique($productIds)), self::CHUNK) as $chunk) {
            $names += StagingProduct::query()
                ->whereIn('external_id', $chunk)
                ->pluck('name', 'external_id')
                ->all();
        }

        return $names;
    }
}
