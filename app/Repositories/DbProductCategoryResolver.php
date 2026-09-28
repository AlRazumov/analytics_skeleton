<?php

namespace App\Repositories;

use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Staging\StagingProduct;
use App\Core\Widgets\Contracts\ProductCategoryResolver;

/** Категории товаров из staging_products: DISTINCT одним запросом. */
final class DbProductCategoryResolver implements ProductCategoryResolver
{
    public function categories(): array
    {
        $categories = [];
        $hasNone = false;
        foreach (StagingProduct::query()->distinct()->pluck('category') as $category) {
            if (is_string($category) && $category !== '') {
                $categories[] = $category;
            } else {
                $hasNone = true;
            }
        }
        sort($categories, SORT_STRING);

        return $hasNone ? [...$categories, CategoryRevenueCalculator::NO_CATEGORY] : $categories;
    }

    public function categoryOf(string $productId): ?string
    {
        $category = StagingProduct::query()->where('external_id', $productId)->value('category');

        return is_string($category) && $category !== '' ? $category : null;
    }
}
