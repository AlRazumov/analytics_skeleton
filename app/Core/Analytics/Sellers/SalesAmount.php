<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Сумма продаж нетто (см. SellerSalesData::fromDeals: возвраты — отрицательными сделками). */
final class SalesAmount implements SellerMetric
{
    public function key(): string
    {
        return 'sales_amount';
    }

    public function label(): string
    {
        return 'Сумма продаж';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        foreach ($data->keys($month) as $key) {
            yield $key => $data->amount($month, $key);
        }
    }
}
