<?php

namespace App\Core\Widgets\Contracts;

use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\RankBy;
use App\Core\Domain\Period;
use App\Core\Widgets\DTO\MetricComparisonRow;
use InvalidArgumentException;

/**
 * Запросы поверх хранимых снэпшотов: сравнение периодов и топ/анти-топ.
 * Ничего не считает и не пишет — только читает то, что уже посчитано.
 *
 * Базовый период ищется по ТОЧНОМУ ключу (тип периода, начало периода),
 * а не «предыдущей строкой»: если снэпшота за предыдущий период нет, база
 * — null, а не сравнение с позапрошлым периодом.
 */
interface MetricsComparisonRepository
{
    /**
     * Строки текущего периода со сравнением с базой, по entity_id по
     * возрастанию. Выборка определяется сущностями ТЕКУЩЕГО периода:
     * сущности, у которых снэпшот есть только в базовом периоде (пропали
     * в текущем), не возвращаются.
     *
     * @return iterable<MetricComparisonRow>
     */
    public function compare(string $metricKey, string $entityType, Period $period, ComparisonBase $base): iterable;

    /**
     * Топ ($dir = Desc) / анти-топ ($dir = Asc) сущностей за период.
     * Сортировка и LIMIT выполняются в БД. Ничьи разрешаются по entity_id
     * по возрастанию (побайтово, COLLATE "C"). При ранжировании по дельте
     * строки, у которых дельта null (нет базы; для DeltaPct — ещё и база 0),
     * исключаются. Строки несут baseValue/дельты, если передан $base.
     *
     * @return list<MetricComparisonRow>
     *
     * @throws InvalidArgumentException если $limit вне 1..1000 или $by
     *                                  требует дельту, а $base не передан
     */
    public function top(
        string $metricKey,
        string $entityType,
        Period $period,
        int $limit,
        RankBy $by = RankBy::Value,
        Direction $dir = Direction::Desc,
        ?ComparisonBase $base = null,
    ): array;
}
