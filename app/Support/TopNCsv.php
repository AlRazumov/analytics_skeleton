<?php

namespace App\Support;

use App\Core\Widgets\DTO\TopNData;
use App\Core\Widgets\DTO\TopNRow;

/**
 * CSV-выгрузка топ-N (формат — Csv). Строка «без сущности» (например
 * «Без продавца») идёт последней, с «—» вместо места. Точность — как на странице
 * (top-n.blade.php): проценты с одним знаком, «%» — в заголовке колонки.
 */
final class TopNCsv
{
    private const array INTEGER_METRICS = ['sales_count'];

    private const array PERCENT_METRICS = ['share_of_total', 'trend'];

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

        return Csv::build($lines);
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
        $precision = match (true) {
            in_array($key, self::INTEGER_METRICS, true) => 0,
            in_array($key, self::PERCENT_METRICS, true) => 1,
            default => 2,
        };

        return Csv::number($value, $precision);
    }
}
