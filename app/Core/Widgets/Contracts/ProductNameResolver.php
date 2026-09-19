<?php

namespace App\Core\Widgets\Contracts;

/**
 * Названия товаров по id для подписей в таблицах. Один вызов на набор id
 * (страницу), а не по одному на строку. Id без названия в результат не
 * попадают — вызывающий код показывает сам id.
 */
interface ProductNameResolver
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, string> productId => название
     */
    public function names(array $productIds): array;
}
