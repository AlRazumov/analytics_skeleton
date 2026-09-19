<?php

namespace App\Core\Domain\Enums;

/** По чему ранжировать строки топа. Delta* требуют базу сравнения. */
enum RankBy: string
{
    case Value = 'value';
    case DeltaAbs = 'delta_abs';
    case DeltaPct = 'delta_pct';
}
