<?php

namespace App\Http\Controllers;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use App\Core\Widgets\WidgetDataProvider;
use DateTimeImmutable;
use Illuminate\View\View;

class DemoWidgetsController extends Controller
{
    public function __invoke(WidgetDataProvider $widgets): View
    {
        $period = new Period(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-06-30'),
            PeriodGranularity::Month,
        );

        return view('demo.widgets', [
            'lineChart' => $widgets->lineChart('product', 'revenue', $period),
            'barChart' => $widgets->lineChartYoY('product', 'revenue', $period),
            'table' => $widgets->table('product', 'revenue', $period),
            'kpiCard' => $widgets->kpiCard('product', 'revenue', $period, unit: '₽'),
            'matrix' => $widgets->abcXyzMatrix($period),
        ]);
    }
}
