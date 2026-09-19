<?php

namespace App\Core\Analytics;

use App\Core\Domain\Enums\PeriodGranularity;
use App\Core\Domain\Period;
use DateTimeImmutable;
use Generator;

/** Календарные месяцы, пересекающие диапазон дат, по возрастанию. */
final class Months
{
    /** @return Generator<Period> */
    public static function in(DateTimeImmutable $start, DateTimeImmutable $end): Generator
    {
        $cursor = Period::containing(PeriodGranularity::Month, $start);
        while ($cursor->start <= $end) {
            yield $cursor;
            $cursor = Period::containing(PeriodGranularity::Month, $cursor->end->modify('+1 day'));
        }
    }
}
