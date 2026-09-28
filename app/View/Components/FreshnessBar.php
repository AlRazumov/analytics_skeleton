<?php

namespace App\View\Components;

use App\Services\DataFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-freshness-bar /> — плашка «когда и за какой период рассчитаны данные»
 * над страницами дашбордов; предупреждает, если последний расчёт упал, идёт
 * или данные устарели. Без журнала расчётов (metrics_runs пуст) — ничего.
 */
class FreshnessBar extends Component
{
    public function render(): View
    {
        $freshness = DataFreshness::at(CarbonImmutable::now(), (int) config('analytics.display.stale_after_hours'));
        $timezone = (string) config('analytics.display.timezone');

        return view('components.freshness-bar', [
            'freshness' => $freshness,
            'time' => static fn (?CarbonImmutable $moment): string => $moment?->setTimezone($timezone)->format('d.m.Y H:i') ?? '—',
            'month' => static fn (CarbonImmutable $date): string => $date->format('Y-m'),
            'staleAfterHours' => (int) config('analytics.display.stale_after_hours'),
        ]);
    }
}
