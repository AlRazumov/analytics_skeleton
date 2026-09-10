<?php

namespace App\Core\Domain\Enums;

enum PeriodGranularity: string
{
    case Month = 'month';
    case Day = 'day';
}
