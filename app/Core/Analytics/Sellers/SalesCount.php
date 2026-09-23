<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Число продаж (сделок) за месяц. */
final class SalesCount implements SellerMetric
{
    public function key(): string
    {
        return 'sales_count';
    }

    public function label(): string
    {
        return 'Количество продаж';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        foreach ($data->keys($month) as $key) {
            yield $key => (float) $data->count($month, $key);
        }
    }
}
