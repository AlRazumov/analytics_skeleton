<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\PeriodGranularity;
use DateTimeImmutable;

/**
 * Диапазон дат вместе с гранулярностью, задающей, как он раскладывается
 * на ключи `metrics_snapshots.period` ('YYYY-MM' для Month, 'YYYY-MM-DD'
 * для Day). В отличие от DateRange (сырой диапазон для fetch*-методов
 * адаптера), Period — понятие слоя агрегированных метрик: он умеет
 * перечислять свои снэпшот-ключи и сдвигаться на год назад для YoY.
 */
final readonly class Period
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public PeriodGranularity $granularity = PeriodGranularity::Month,
    ) {}

    /**
     * Ключи `metrics_snapshots.period`, покрывающие диапазон, по
     * возрастанию.
     *
     * @return string[]
     */
    public function keys(): array
    {
        $format = $this->granularity === PeriodGranularity::Month ? 'Y-m' : 'Y-m-d';
        $step = $this->granularity === PeriodGranularity::Month ? '+1 month' : '+1 day';

        $cursor = $this->granularity === PeriodGranularity::Month
            ? new DateTimeImmutable($this->start->format('Y-m-01'))
            : $this->start;
        $last = $this->granularity === PeriodGranularity::Month
            ? new DateTimeImmutable($this->end->format('Y-m-01'))
            : $this->end;

        $keys = [];
        while ($cursor <= $last) {
            $keys[] = $cursor->format($format);
            $cursor = $cursor->modify($step);
        }

        return $keys;
    }

    /**
     * Тот же диапазон, сдвинутый на год назад — используется для
     * сравнения "год к году" (YoY).
     */
    public function previousYear(): self
    {
        return new self(
            $this->start->modify('-1 year'),
            $this->end->modify('-1 year'),
            $this->granularity,
        );
    }
}
