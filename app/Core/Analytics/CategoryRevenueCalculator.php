<?php

namespace App\Core\Analytics;

use App\Core\Domain\Deal;
use App\Core\Domain\Product;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

/**
 * Выручка по (категория, месяц): сумма Deal::amount товаров категории, один
 * снэпшот на пару, entity_type='category', metric_key='revenue' (тот же
 * ключ, что у товаров, — различает entity_type). value_meta.products_sold —
 * число разных товаров со сделками в месяце.
 *
 * Категория — Product::$category как есть (строка — и ключ, и подпись).
 * Товары без категории и сделки по товарам, которых нет в справочнике,
 * идут под NO_CATEGORY, чтобы сумма по категориям совпадала с выручкой
 * товаров.
 */
final class CategoryRevenueCalculator
{
    public const string ENTITY_TYPE = 'category';

    public const string METRIC_KEY = 'revenue';

    public const string NO_CATEGORY = '__none__';

    /**
     * @param  iterable<Deal>  $deals
     * @param  iterable<Product>  $products
     * @return MetricsSnapshotRecord[]
     */
    public function calculate(iterable $deals, iterable $products): array
    {
        $categoryOf = [];
        foreach ($products as $product) {
            $categoryOf[$product->id] = $product->category === null || $product->category === '' ? self::NO_CATEGORY : $product->category;
        }

        $revenue = [];
        $sold = [];
        foreach ($deals as $deal) {
            $category = $categoryOf[$deal->productId] ?? self::NO_CATEGORY;
            $month = $deal->date->format('Y-m');
            $revenue[$category][$month] = ($revenue[$category][$month] ?? 0.0) + $deal->amount;
            $sold[$category][$month][$deal->productId] = true;
        }

        $records = [];
        foreach ($revenue as $category => $byMonth) {
            foreach ($byMonth as $month => $value) {
                $records[] = new MetricsSnapshotRecord(
                    entityType: self::ENTITY_TYPE,
                    entityId: (string) $category,
                    metricKey: self::METRIC_KEY,
                    value: $value,
                    period: 'month:'.$month,
                    valueMeta: ['products_sold' => count($sold[$category][$month])],
                );
            }
        }

        return $records;
    }
}
