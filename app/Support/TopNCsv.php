<?php

namespace App\Support;

use App\Core\Widgets\DTO\TopNData;
use App\Core\Widgets\DTO\TopNRow;
use RuntimeException;

/**
 * CSV-выгрузка топ-N под русский Excel: UTF-8 с BOM, разделитель «;»,
 * десятичная запятая без разделителя разрядов (иначе Excel прочитает
 * число как текст). Строка «без сущности» (например «Без продавца»)
 * идёт последней, с «—» вместо места.
 */
final class TopNCsv
{
    private const string BOM = "\xEF\xBB\xBF";

    private const array INTEGER_METRICS = ['sales_count'];

    public static function build(TopNData $data): string
    {
        $columns = [$data->metric, ...$data->columns];

        $lines = [['#', 'Название', 'Идентификатор', ...array_map(MetricLabels::label(...), $columns)]];
        foreach ($data->rows as $i => $row) {
            $lines[] = [(string) ($i + 1), ...self::cells($row, $data->metric, $columns)];
        }
        if ($data->unassigned !== null) {
            $lines[] = ['—', ...self::cells($data->unassigned, $data->metric, $columns)];
        }

        $out = fopen('php://temp', 'r+') ?: throw new RuntimeException('Не удалось открыть php://temp для CSV.');
        foreach ($lines as $line) {
            fputcsv($out, $line, ';', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return self::BOM.$csv;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function cells(TopNRow $row, string $metric, array $columns): array
    {
        return [
            $row->name,
            $row->id,
            ...array_map(
                fn (string $key) => self::number($key, $key === $metric ? $row->value : ($row->extras[$key] ?? 0.0)),
                $columns,
            ),
        ];
    }

    private static function number(string $key, float $value): string
    {
        $precision = in_array($key, self::INTEGER_METRICS, true) ? 0 : 2;

        return number_format($value, $precision, ',', '');
    }
}
