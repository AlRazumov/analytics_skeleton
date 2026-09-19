<?php

namespace App\Adapters;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Widgets\Contracts\ProductNameResolver;

/**
 * Названия товаров из DataSourceAdapter::fetchProducts(). Контракт
 * адаптера не даёт выборки по id, поэтому каталог читается потоком один
 * раз за вызов и фильтруется в памяти; запросов к БД не делает.
 */
final class AdapterProductNameResolver implements ProductNameResolver
{
    public function __construct(private readonly DataSourceAdapter $adapter) {}

    public function names(array $productIds): array
    {
        $wanted = array_flip($productIds);
        $names = [];

        foreach ($this->adapter->fetchProducts() as $product) {
            if (isset($wanted[$product->id])) {
                $names[$product->id] = $product->name;
                if (count($names) === count($wanted)) {
                    break;
                }
            }
        }

        return $names;
    }
}
