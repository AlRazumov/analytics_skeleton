<?php

namespace App\Repositories;

use App\Core\Staging\StagingProduct;
use App\Core\Widgets\Contracts\ProductNameResolver;

/** Названия товаров из справочника в БД: один запрос whereIn на набор id. */
final class DbProductNameResolver implements ProductNameResolver
{
    public function names(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return StagingProduct::query()
            ->whereIn('external_id', array_values(array_unique($productIds)))
            ->pluck('name', 'external_id')
            ->all();
    }
}
