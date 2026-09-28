<?php

namespace App\Repositories;

use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Analytics\ProductWarehouseKey;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Фильтр строк metrics_snapshots по категории товара из справочника
 * (staging_products), общий для репозиториев. NO_CATEGORY — как у
 * CategoryRevenueCalculator: категория null или пустая, или товара нет в
 * справочнике. Товар — entity_id для product, часть до ':' для product_warehouse.
 */
final class ProductCategoryFilter
{
    /** @param  literal-string  $entityIdColumn  колонка entity_id в запросе (с алиасом таблицы); идёт в SQL как есть */
    public static function apply(Builder $query, string $entityIdColumn, string $entityType, ?string $category): void
    {
        if ($category === null) {
            return;
        }

        $productId = match ($entityType) {
            'product' => $entityIdColumn,
            ProductWarehouseKey::ENTITY_TYPE => "split_part({$entityIdColumn}, ':', 1)",
            default => throw new InvalidArgumentException("Фильтр по категории товара неприменим к entity_type '{$entityType}'."),
        };

        if ($category === CategoryRevenueCalculator::NO_CATEGORY) {
            $query->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')->from('staging_products as sp')
                ->whereRaw("sp.external_id = {$productId}")
                ->whereNotNull('sp.category')->where('sp.category', '<>', ''));

            return;
        }

        $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('staging_products as sp')
            ->whereRaw("sp.external_id = {$productId}")
            ->where('sp.category', $category));
    }
}
