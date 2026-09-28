<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\Contracts\ProductCategoryResolver;
use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Демо-страница дашборда "ABC/XYZ-анализ" на StandaloneLayout.
 * `?category=` — только товары категории; классы — по всему ассортименту.
 */
class AbcXyzDashboardController extends Controller
{
    use ResolvesCategory;

    public function __invoke(Request $request, WidgetDataProvider $widgets, ProductCategoryResolver $categories): View
    {
        $options = $this->categoryOptions($categories);
        $category = $this->requestedCategory($request, $options);

        return view('dashboards.abc-xyz', [
            'matrix' => $widgets->abcXyzMatrix($category),
            'category' => $category,
            'categoryOptions' => $options,
        ]);
    }
}
