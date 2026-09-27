<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Widgets\CategoryProvider;
use App\Core\Widgets\Contracts\ProductCategoryResolver;
use Illuminate\Http\Request;

/**
 * Необязательный `?category=<ключ категории>`: null (нет или пусто) — все товары; ключ, которого
 * нет среди вариантов, или не строка — 404. Фильтр живёт вместе с разрезом по
 * категориям: при выключенном флаге `categories` вариантов нет (параметр — 404,
 * фильтр не рисуется).
 */
trait ResolvesCategory
{
    /**
     * Варианты для <x-widgets.category-filter> — список пар, а не массив
     * ключ => подпись: числовой ключ категории (код группы) PHP превратил бы в int.
     *
     * @return list<array{key: string, label: string}>
     */
    private function categoryOptions(ProductCategoryResolver $categories): array
    {
        if (! config('analytics.features.categories')) {
            return [];
        }

        return array_map(
            static fn (string $key) => ['key' => $key, 'label' => CategoryProvider::label($key)],
            $categories->categories(),
        );
    }

    /** @param list<array{key: string, label: string}> $options */
    private function requestedCategory(Request $request, array $options): ?string
    {
        // Пустое значение — пункт «Все категории» формы фильтра (middleware
        // ConvertEmptyStringsToNull превращает его в null).
        if (in_array($request->query('category'), [null, ''], true)) {
            return null;
        }

        // NO_CATEGORY допустим и без варианта в списке: строка «Без категории» на
        // странице категорий бывает из-за сделок по товарам вне справочника.
        $category = $request->query('category');
        $known = $options === [] ? [] : [...array_column($options, 'key'), CategoryRevenueCalculator::NO_CATEGORY];
        if (! is_string($category) || ! in_array($category, $known, true)) {
            abort(404);
        }

        return $category;
    }
}
