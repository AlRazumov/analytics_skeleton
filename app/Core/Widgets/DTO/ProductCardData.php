<?php

namespace App\Core\Widgets\DTO;

/**
 * Карточка товара за месяц $period (null — метрики ещё не рассчитывались;
 * тогда пусты и остальные поля). $history — рассчитанные месяцы окна по
 * возрастанию, последний — $period. $previousRevenue — выручка предыдущего
 * месяца (null — он не рассчитан). ABC/XYZ — из последнего расчёта
 * классификации ($abcXyzPeriod), не из $period. $category — ключ категории
 * справочника (null — без категории).
 */
final readonly class ProductCardData
{
    /**
     * @param  list<ProductMonthRow>  $history
     * @param  list<ProductWarehouseRow>  $warehouses
     */
    public function __construct(
        public string $productId,
        public string $productName,
        public ?string $category,
        public ?string $period,
        public ?ProductMonthRow $month,
        public ?float $previousRevenue,
        public ?float $daysSinceLastSale,
        public bool $noSalesInLookback,
        public ?float $lostSales,
        public ?string $lostSalesFrom,
        public ?string $abcClass,
        public ?string $xyzClass,
        public ?string $abcXyzPeriod,
        public array $history,
        public array $warehouses,
    ) {}

    /** Изменение выручки к предыдущему месяцу, %; null — базы нет или она 0. */
    public function revenueDeltaPercent(): ?float
    {
        if ($this->month === null || $this->previousRevenue === null || $this->previousRevenue === 0.0) {
            return null;
        }

        return ($this->month->revenue - $this->previousRevenue) / abs($this->previousRevenue) * 100;
    }
}
