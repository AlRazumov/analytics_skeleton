<?php

namespace App\Core\Widgets;

use App\Core\Domain\Period;
use App\Core\Widgets\Contracts\MetricsComparisonRepository;
use App\Core\Widgets\DTO\MonthNavigation;

/**
 * Предыдущий и следующий месяц для переключателя на странице: ближайшие
 * месяцы, за которые есть снэпшоты метрик страницы. Показанный месяц
 * страница передаёт сама — тот, что она уже определила (запрошенный или
 * последний с данными).
 */
final readonly class MonthNavigationProvider
{
    public function __construct(private MetricsComparisonRepository $repository) {}

    /** @param  non-empty-list<array{string, string}>  $metrics  пары [entity_type, metric_key] */
    public function for(?string $currentKey, array $metrics): MonthNavigation
    {
        if ($currentKey === null) {
            return MonthNavigation::none();
        }

        [$previous, $next] = $this->repository->adjacentPeriods($metrics, Period::fromKey($currentKey));

        return new MonthNavigation($currentKey, $previous?->key(), $next?->key());
    }
}
