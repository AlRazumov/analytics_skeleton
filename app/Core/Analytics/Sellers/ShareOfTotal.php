<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Доля в общем количестве продаж месяца (включая «без продавца»), в процентах. */
final class ShareOfTotal implements SellerMetric
{
    public function key(): string
    {
        return 'share_of_total';
    }

    public function label(): string
    {
        return 'Доля от всех продаж, %';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        $total = $data->totalCount($month);
        if ($total === 0) {
            return;
        }
        foreach ($data->keys($month) as $key) {
            yield $key => $data->count($month, $key) / $total * 100;
        }
    }
}
