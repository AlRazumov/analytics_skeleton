<?php

namespace App\Console\Commands\Concerns;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Contracts\ProvidesHistoryBounds;
use App\Core\Domain\DateRange;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Опция `--period=YYYY-MM:YYYY-MM` команд (metrics:calculate, adapter:check):
 * целые месяцы включительно. Без опции — $defaultMonths месяцев,
 * заканчивающихся концом истории адаптера (ProvidesHistoryBounds, например
 * MockAdapter) либо текущим месяцем.
 */
trait ResolvesMonthRange
{
    private function monthRange(?string $value, DataSourceAdapter $adapter, int $defaultMonths): DateRange
    {
        $back = '-'.($defaultMonths - 1).' months';

        if ($value === null || $value === '') {
            if ($adapter instanceof ProvidesHistoryBounds) {
                $end = $adapter->historyEnd();

                return new DateRange((new DateTimeImmutable($end->format('Y-m-01')))->modify($back), $end);
            }

            $now = new DateTimeImmutable('first day of this month');

            return new DateRange($now->modify($back), $now->modify('last day of this month'));
        }

        if (! preg_match('/^(\d{4}-\d{2}):(\d{4}-\d{2})$/', $value, $matches)) {
            throw new InvalidArgumentException("Неверный формат --period='{$value}'. Ожидается 'YYYY-MM:YYYY-MM'.");
        }

        try {
            $start = new DateTimeImmutable($matches[1].'-01');
            $end = new DateTimeImmutable($matches[2].'-01');
        } catch (\Exception) {
            throw new InvalidArgumentException("Неверный формат --period='{$value}'. Ожидается 'YYYY-MM:YYYY-MM'.");
        }

        if ($start > $end) {
            throw new InvalidArgumentException("Неверный --period='{$value}': начало периода позже конца.");
        }

        return new DateRange($start, $end->modify('last day of this month'));
    }
}
