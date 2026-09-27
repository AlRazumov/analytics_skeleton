<?php

namespace App\Support;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Transfers\TransferDonorReason;
use App\Core\Widgets\DTO\CategoryRow;
use App\Core\Widgets\DTO\DeadStockRow;
use App\Core\Widgets\DTO\RankedTableData;
use App\Core\Widgets\DTO\StockoutRiskRow;
use App\Core\Widgets\DTO\TopProductRow;
use App\Core\Widgets\DTO\TransferRow;
use App\Core\Widgets\DTO\TransferTableData;
use App\Core\Widgets\DTO\TurnoverRow;

/**
 * CSV-выгрузки табличных страниц (формат — Csv). Колонки и точность — как
 * в таблицах на странице (компоненты widgets/*-table и страницы
 * categories, transfers), плюс «#» и идентификаторы сущностей, чтобы
 * строки можно было сопоставить с учётной системой. Выгружаются все
 * строки, а не лимит страницы.
 */
final class TableCsv
{
    /** @param RankedTableData<TopProductRow> $data */
    public static function topProducts(RankedTableData $data, ComparisonBase $base): string
    {
        [$baseColumn, $deltaSuffix] = self::baseLabels($base);

        return self::ranked(
            ['Товар', 'Идентификатор', 'Выручка', $baseColumn, "Изменение, {$deltaSuffix}", "Изменение, % {$deltaSuffix}"],
            $data->rows,
            static fn (TopProductRow $r) => [
                $r->productName, $r->productId, Csv::number($r->value), Csv::number($r->baseValue),
                Csv::number($r->deltaAbs), Csv::number($r->deltaPct),
            ],
        );
    }

    /** @param RankedTableData<CategoryRow> $data */
    public static function categories(RankedTableData $data, ComparisonBase $base): string
    {
        [$baseColumn, $deltaSuffix] = self::baseLabels($base);

        return self::ranked(
            ['Категория', 'Идентификатор', 'Выручка', 'Доля, %', $baseColumn, "Изменение, {$deltaSuffix}", "Изменение, % {$deltaSuffix}", 'Продано товаров'],
            $data->rows,
            static fn (CategoryRow $r) => [
                $r->categoryName, $r->categoryId, Csv::number($r->value), Csv::number($r->sharePct, 1),
                Csv::number($r->baseValue), Csv::number($r->deltaAbs), Csv::number($r->deltaPct), Csv::number($r->productsSold, 0),
            ],
        );
    }

    /**
     * «Без продаж за весь период» — на странице это «≥» перед числом дней:
     * продаж не было за всю просмотренную историю, число дней — нижняя граница.
     *
     * @param  RankedTableData<DeadStockRow>  $data
     */
    public static function deadStock(RankedTableData $data): string
    {
        return self::ranked(
            ['Товар', 'Идентификатор', 'Остаток, шт.', 'Дней без продаж', 'Без продаж за весь просмотренный период'],
            $data->rows,
            static fn (DeadStockRow $r) => [
                $r->productName, $r->productId, Csv::number($r->stockQty), Csv::number($r->daysSinceLastSale, 0),
                $r->lowerBound ? 'да' : 'нет',
            ],
        );
    }

    /** @param RankedTableData<StockoutRiskRow> $data */
    public static function stockoutRisk(RankedTableData $data): string
    {
        return self::ranked(
            ['Товар', 'Идентификатор товара', 'Склад', 'Идентификатор склада', 'Остаток, шт.', 'Продаж в день, шт.', 'Дней до обнуления'],
            $data->rows,
            static fn (StockoutRiskRow $r) => [
                $r->productName, $r->productId, $r->warehouseName, $r->warehouseId,
                Csv::number($r->stockQty), Csv::number($r->dailyRate), Csv::number($r->daysOfStock, 1),
            ],
        );
    }

    /** @param RankedTableData<TurnoverRow> $data */
    public static function turnover(RankedTableData $data): string
    {
        return self::ranked(
            ['Товар', 'Идентификатор', 'Остаток на конец месяца, шт.', 'Продано, шт.', 'Оборачиваемость'],
            $data->rows,
            static fn (TurnoverRow $r) => [
                $r->productName, $r->productId, Csv::number($r->closingStock), Csv::number($r->unitsSold), Csv::number($r->turnover),
            ],
        );
    }

    /**
     * Покрытие донора без продаж (stock_surplus) бесконечно — пустая ячейка,
     * как «—» на странице.
     */
    public static function transfers(TransferTableData $data): string
    {
        return self::ranked(
            [
                'Товар', 'Идентификатор товара', 'Откуда', 'Идентификатор склада «откуда»', 'Донор',
                'Куда', 'Идентификатор склада «куда»', 'Количество, шт.',
                'Покрытие «откуда» до, дней', 'Покрытие «откуда» после, дней',
                'Покрытие «куда» до, дней', 'Покрытие «куда» после, дней', 'Продаж «куда» в день, шт.',
            ],
            $data->rows,
            static fn (TransferRow $r) => [
                $r->productName, $r->productId, $r->fromWarehouseName, $r->fromWarehouseId, self::donorLabel($r->donorReason),
                $r->toWarehouseName, $r->toWarehouseId, Csv::number($r->quantity, 0),
                Csv::number($r->fromCoverageBefore, 1), Csv::number($r->fromCoverageAfter, 1),
                Csv::number($r->toCoverageBefore, 1), Csv::number($r->toCoverageAfter, 1), Csv::number($r->toDailyRate),
            ],
        );
    }

    /**
     * @template T
     *
     * @param  list<string>  $headers
     * @param  list<T>  $rows
     * @param  callable(T): list<string>  $cells
     */
    private static function ranked(array $headers, array $rows, callable $cells): string
    {
        $lines = [['#', ...$headers]];
        foreach ($rows as $i => $row) {
            $lines[] = [(string) ($i + 1), ...$cells($row)];
        }

        return Csv::build($lines);
    }

    /** @return array{string, string} [заголовок колонки базы, суффикс колонок изменения] — как на странице */
    private static function baseLabels(ComparisonBase $base): array
    {
        return $base === ComparisonBase::YearAgo
            ? ['Тот же месяц год назад', 'к тому же месяцу прошлого года']
            : ['Прошлый месяц', 'к пред. месяцу'];
    }

    private static function donorLabel(TransferDonorReason $reason): string
    {
        return match ($reason) {
            TransferDonorReason::Turnover => 'по обороту',
            TransferDonorReason::StockSurplus => 'по остатку (без продаж)',
        };
    }
}
