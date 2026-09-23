<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Сумма / количество; продавцы без продаж за месяц пропускаются. */
final class AvgCheck implements SellerMetric
{
    public function key(): string
    {
        return 'avg_check';
    }

    public function label(): string
    {
        return 'Средний чек';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        foreach ($data->keys($month) as $key) {
            $count = $data->count($month, $key);
            if ($count > 0) {
                yield $key => $data->amount($month, $key) / $count;
            }
        }
    }
}
