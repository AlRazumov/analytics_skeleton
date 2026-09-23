<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Изменение количества продаж к предыдущему месяцу, %; без базы (нет продаж) — пропуск. */
final class Trend implements SellerMetric
{
    public function key(): string
    {
        return 'trend';
    }

    public function label(): string
    {
        return 'Изменение к пред. месяцу, %';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        $previous = $period->previous()->start->format('Y-m');
        foreach ($data->keys($month) as $key) {
            $before = $data->count($previous, $key);
            if ($before > 0) {
                yield $key => ($data->count($month, $key) - $before) / $before * 100;
            }
        }
    }
}
