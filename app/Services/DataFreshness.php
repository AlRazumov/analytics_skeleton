<?php

namespace App\Services;

use App\Models\MetricsRun;
use Carbon\CarbonImmutable;

/**
 * Свежесть данных на страницах по журналу metrics_runs: последний успешный
 * расчёт, более поздняя неуспешная или идущая попытка (если есть) и
 * устарели ли данные (успешный расчёт старше analytics.display.stale_after_hours).
 */
final readonly class DataFreshness
{
    public function __construct(
        public ?MetricsRun $lastSuccess,
        public ?MetricsRun $laterAttempt,
        public bool $stale,
    ) {}

    public static function at(CarbonImmutable $now, int $staleAfterHours): self
    {
        MetricsRun::failAbandoned($now);

        $lastSuccess = MetricsRun::query()
            ->where('status', MetricsRun::SUCCESS)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        $laterAttempt = MetricsRun::query()
            ->where('status', '<>', MetricsRun::SUCCESS)
            ->when($lastSuccess !== null, fn ($query) => $query->where('started_at', '>', $lastSuccess?->started_at))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $stale = $lastSuccess?->finished_at !== null
            && $lastSuccess->finished_at->lt($now->subHours($staleAfterHours));

        return new self($lastSuccess, $laterAttempt, $stale);
    }
}
