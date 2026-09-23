<?php

namespace App\Repositories;

use App\Core\Staging\StagingSeller;
use App\Core\Widgets\Contracts\EntityNameResolver;
use App\Core\Widgets\Contracts\ProductNameResolver;

/** Названия из справочников в БД: product — через ProductNameResolver, seller — staging_sellers. */
final class DbEntityNameResolver implements EntityNameResolver
{
    public function __construct(private ProductNameResolver $products) {}

    public function names(string $entityType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return match ($entityType) {
            'product' => $this->products->names($ids),
            'seller' => StagingSeller::query()
                ->whereIn('external_id', array_values(array_unique($ids)))
                ->pluck('name', 'external_id')
                ->all(),
            default => [],
        };
    }
}
