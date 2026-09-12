<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Демо-страница дашборда "ABC/XYZ-анализ" на StandaloneLayout.
 */
class AbcXyzDashboardController extends Controller
{
    public function __invoke(WidgetDataProvider $widgets): View
    {
        return view('dashboards.abc-xyz', [
            'matrix' => $widgets->abcXyzMatrix(),
        ]);
    }
}
