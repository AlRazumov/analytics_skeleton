<?php

namespace App\Core\Domain;

use DateTimeImmutable;

final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}
}
