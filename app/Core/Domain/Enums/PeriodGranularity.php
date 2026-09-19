<?php

namespace App\Core\Domain\Enums;

enum PeriodGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
