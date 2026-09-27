<?php

namespace App\Support;

use RuntimeException;

/**
 * CSV под русский Excel: UTF-8 с BOM, разделитель «;», десятичная запятая
 * без разделителя разрядов (иначе Excel прочитает число как текст).
 * Пустое значение (null) — пустая ячейка, а не «—»: в числовой колонке
 * Excel иначе получит текст.
 */
final class Csv
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param  iterable<list<string>>  $lines  первая строка — заголовки
     */
    public static function build(iterable $lines): string
    {
        $out = fopen('php://temp', 'r+') ?: throw new RuntimeException('Не удалось открыть php://temp для CSV.');
        foreach ($lines as $line) {
            fputcsv($out, $line, ';', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return self::BOM.$csv;
    }

    /** Число без разрядов, с десятичной запятой; null и бесконечность — пустая ячейка. */
    public static function number(float|int|null $value, int $precision = 2): string
    {
        if ($value === null || is_infinite((float) $value)) {
            return '';
        }

        return number_format((float) $value, $precision, ',', '');
    }
}
