<?php

namespace App\Core\Widgets\Contracts;

/**
 * Категории товаров из справочника — варианты фильтра `?category=` на
 * страницах. Ключ категории — та же строка, что entity_id снэпшотов
 * категорий (CategoryRevenueCalculator).
 */
interface ProductCategoryResolver
{
    /**
     * Ключи категорий по алфавиту; CategoryRevenueCalculator::NO_CATEGORY —
     * последним, если в справочнике есть товары без категории.
     *
     * @return list<string>
     */
    public function categories(): array;

    /**
     * Категория товара по справочнику; null — категории нет (null или
     * пустая строка) или товара нет в справочнике.
     */
    public function categoryOf(string $productId): ?string;
}
