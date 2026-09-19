<?php

namespace App\Core\Domain\Enums;

/** С каким периодом сравнивается текущий: предыдущий (MoM/WoW/QoQ) или год назад (YoY). */
enum ComparisonBase: string
{
    case Previous = 'previous';
    case YearAgo = 'year_ago';
}
