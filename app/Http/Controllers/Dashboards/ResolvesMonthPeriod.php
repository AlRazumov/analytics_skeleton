<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Необязательный `?period=month:2026-08`: null — не задан (страница берёт
 * последний период метрики); иной или невалидный ключ — 404.
 */
trait ResolvesMonthPeriod
{
    private function requestedMonth(Request $request): ?Period
    {
        if (! $request->query->has('period')) {
            return null;
        }

        $key = $request->query('period');
        if (! is_string($key)) {
            abort(404);
        }

        try {
            $period = Period::fromKey($key);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if ($period->granularity !== PeriodGranularity::Month) {
            abort(404);
        }

        return $period;
    }
}
