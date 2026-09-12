<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\WidgetDataProvider;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\View\View;

/**
 * Демо-страница дашборда "ABC/XYZ-анализ" на StandaloneLayout.
 */
class AbcXyzDashboardController extends Controller
{
    public function __invoke(WidgetDataProvider $widgets): View
    {
        $period = new Period(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-06-30'),
            PeriodGranularity::Month,
        );

        return view('dashboards.abc-xyz', [
            'matrix' => $widgets->abcXyzMatrix($period),
        ]);
    }
}
