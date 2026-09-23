<?php

namespace App\Core\Contracts;

use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\AdapterCapability;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Product;
use App\Core\Domain\Seller;
use App\Core\Domain\StockBalance;
use App\Core\Domain\StockMovement;
use App\Core\Domain\Warehouse;
use DateTimeImmutable;

/**
 * Потоковый контракт источника данных.
 *
 * Каждый метод вызывается один раз за прогон синхронизации (не для
 * каждого расчёта отдельно). Реализация не должна кешировать
 * состояние между вызовами — каждый вызов самодостаточен.
 */
interface DataSourceAdapter
{
    /**
     * @return iterable<Deal>
     */
    public function fetchDeals(DateRange $period): iterable;

    /**
     * @return iterable<Product>
     */
    public function fetchProducts(): iterable;

    /**
     * Справочник складов (id, название). Адаптер, не умеющий отдавать
     * склады, возвращает пустой iterable — отдельной capability нет.
     *
     * @return iterable<Warehouse>
     */
    public function fetchWarehouses(): iterable;

    /**
     * Справочник продавцов. Источник без продавцов возвращает пустой
     * iterable (и sellerCoverage() = None).
     *
     * @return iterable<Seller>
     */
    public function fetchSellers(): iterable;

    /**
     * У каких сделок из fetchDeals() заполнен sellerId: у всех, у части
     * или ни у одной. Блок продавцов на дашборде зависит от этого значения.
     */
    public function sellerCoverage(): SellerCoverage;

    /**
     * Движения за диапазон; обе границы DateRange включительно по
     * дате. Порядок записей не гарантируется. Вызывать только если
     * capabilities() содержит AdapterCapability::StockMovements.
     *
     * @return iterable<StockMovement>
     */
    public function fetchStockMovements(DateRange $period): iterable;

    /**
     * Остатки на КОНЕЦ дня $asOf (время игнорируется); null — текущие.
     * Вызывать только если capabilities() содержит
     * AdapterCapability::StockSnapshots.
     *
     * @return iterable<StockBalance>
     */
    public function fetchStock(?DateTimeImmutable $asOf = null): iterable;

    /**
     * Что источник умеет отдавать. Метрика, которой нужна история
     * движений или остатки, сверяется с этим списком вместо падения.
     *
     * @return list<AdapterCapability>
     */
    public function capabilities(): array;
}
