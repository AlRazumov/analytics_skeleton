<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\PeriodGranularity;
use DateTimeImmutable;

/**
 * Диапазон дат вместе с гранулярностью, задающей, как он раскладывается
 * на периоды (Period) и их канонические ключи. Раньше назывался Period;
 * переименован, когда имя занял value object одного календарного
 * периода. В отличие от DateRange (сырой диапазон для fetch*-методов
 * адаптера), Period — понятие слоя агрегированных метрик: он умеет
 * перечислять свои снэпшот-ключи и сдвигаться на год назад для YoY.
 */
final readonly class PeriodRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public PeriodGranularity $granularity = PeriodGranularity::Month,
    ) {}

    /**
     * Канонические ключи (Period::key()) периодов гранулярности
     * диапазона, покрывающих его, по возрастанию.
     *
     * @return string[]
     */
    public function keys(): array
    {
        $keys = [];
        $cursor = Period::containing($this->granularity, $this->start);
        $last = Period::containing($this->granularity, $this->end);

        while ($cursor->start <= $last->start) {
            $keys[] = $cursor->key();
            $cursor = Period::containing($this->granularity, $cursor->end->modify('+1 day'));
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
