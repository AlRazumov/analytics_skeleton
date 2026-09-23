<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Period;

/** Продаж за месяц / число дней активного окна продавца; без продаж в окне — пропуск. */
final class SalesPerActiveDay implements SellerMetric
{
    public function key(): string
    {
        return 'sales_per_active_day';
    }

    public function label(): string
    {
        return 'Продаж в активный день';
    }

    public function entityType(): string
    {
        return 'seller';
    }

    public function compute(SellerSalesData $data, Period $period): iterable
    {
        $month = $period->start->format('Y-m');
        $monthStart = $period->start->format('Y-m-d');
        $monthEnd = $period->end->format('Y-m-d');
        foreach ($data->keys($month) as $key) {
            $activity = $data->activity($key);
            if ($key === SellerSalesData::NO_SELLER || $activity === null) {
                continue;
            }
            // Активное окно — от первой до последней продажи продавца в данных,
            // усечённое месяцем: новичок и уволенный не штрафуются за дни вне работы.
            $from = max($monthStart, $activity[0]);
            $to = min($monthEnd, $activity[1]);
            if ($from > $to) {
                continue;
            }
            $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
            yield $key => $data->count($month, $key) / $days;
        }
    }
}
