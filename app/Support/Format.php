<?php

namespace App\Support;

/** Форматирование чисел для таблиц: разряды пробелом, десятичная запятая, без хвостовых нулей. */
final class Format
{
    public static function num(float|int $value, int $precision = 2): string
    {
        $formatted = number_format((float) $value, $precision, ',', ' ');

        return $precision > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
