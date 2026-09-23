<?php

namespace App\Core\Transfers;

use InvalidArgumentException;

/**
 * Планировщик перемещений товара между складами. Чистая функция: без БД,
 * конфига и текущего времени; одинаковый вход (в любом порядке) даёт
 * одинаковый результат.
 *
 * Покрытие пары = остаток / скорость продаж (дней). Пары со скоростью <= 0
 * или отрицательным остатком игнорируются.
 *  - дефицит: покрытие <= deficitDays; потребность = скорость * targetDays - остаток;
 *  - донор: покрытие >= surplusDays; доступно = остаток - скорость * keepDays (если > 0).
 * Валидация: deficitDays < targetDays <= keepDays <= surplusDays, minQuantity > 0
 * (поэтому пара не бывает дефицитом и донором одновременно).
 *
 * Каждый товар планируется отдельно. Дефициты — по возрастанию покрытия
 * (самые срочные первыми; ничья — warehouseId по возрастанию). Для каждого
 * дефицита доноры — по убыванию оставшегося доступного объёма (ничья —
 * warehouseId), берётся min(остаток потребности, доступно донору). Донор
 * может обслуживать несколько дефицитов (доступное уменьшается), дефицит —
 * иметь несколько доноров. Количество строки округляется вниз до целых,
 * строки < minQuantity отбрасываются (донор при этом не тратится), остаток
 * потребности уменьшается на округлённое количество.
 *
 * «Покрытие после» — нарастающим итогом: у получателя после этой и всех
 * предыдущих строк этого дефицита, у донора — после этой и всех предыдущих
 * его строк по товару. Получатель никогда не превышает targetDays, донор
 * не опускается ниже keepDays.
 *
 * Порядок результата: toCoverageBefore ↑, productId, toWarehouseId,
 * fromWarehouseId (строки — побайтовое сравнение). Нарастающий итог
 * «покрытия после» считается в порядке распределения (по убыванию
 * доступного объёма донора), а не в порядке вывода.
 *
 * ОГРАНИЧЕНИЯ: не учитывает сроки доставки, сезонность, минимальные партии
 * поставщика и прочее; покрытие считается по скорости продаж окна метрики
 * days_of_stock, а не по прогнозу.
 *
 * $stockSurplusDonors (необязательный второй аргумент plan()) — ЭВРИСТИКА
 * ДЛЯ ДЕМО (см. TransferDonorReason::StockSurplus): доноры без спроса,
 * $available которых уже готово к раздаче (посчитано вызывающим кодом, не
 * этим классом). Участвуют в раздаче наравне с обычными донорами (тот же
 * порядок по убыванию $available), но не завязаны на deficit_days/
 * target_days/keep_days/surplus_days — эти пороги для них не действуют,
 * поскольку у них нет скорости продаж, по которой их считать. Сами
 * дефицитом стать не могут (по определению — только продукты с деньгами
 * из $positions, у которых есть скорость продаж, оцениваются как дефицит).
 */
final class TransferPlanner
{
    public function __construct(
        private readonly float $deficitDays,
        private readonly float $targetDays,
        private readonly float $keepDays,
        private readonly float $surplusDays,
        private readonly int $minQuantity,
    ) {
        if (! ($deficitDays < $targetDays && $targetDays <= $keepDays && $keepDays <= $surplusDays)) {
            throw new InvalidArgumentException('Пороги должны удовлетворять deficit_days < target_days <= keep_days <= surplus_days.');
        }
        if ($minQuantity <= 0) {
            throw new InvalidArgumentException('min_quantity должен быть > 0.');
        }
    }

    /**
     * @param  iterable<TransferPosition>  $positions
     * @param  iterable<StockSurplusDonor>  $stockSurplusDonors
     */
    public function plan(iterable $positions, iterable $stockSurplusDonors = []): TransferPlan
    {
        $byProduct = [];
        foreach ($positions as $position) {
            if ($position->dailyRate <= 0 || $position->stock < 0) {
                continue;
            }
            $byProduct[$position->productId][] = $position;
        }

        $surplusByProduct = [];
        foreach ($stockSurplusDonors as $donor) {
            if ($donor->available <= 0) {
                continue;
            }
            $surplusByProduct[$donor->productId][] = $donor;
        }

        $recommendations = [];
        $deficitPairs = 0;
        $unmatched = 0;
        foreach (array_unique([...array_keys($byProduct), ...array_keys($surplusByProduct)]) as $productId) {
            $deficits = [];
            $donors = [];
            foreach ($byProduct[$productId] ?? [] as $p) {
                $coverage = $p->stock / $p->dailyRate;
                if ($coverage <= $this->deficitDays) {
                    $deficits[] = [$p, $coverage];
                } elseif ($coverage >= $this->surplusDays && $p->stock - $p->dailyRate * $this->keepDays > 0) {
                    $donors[] = [
                        'warehouseId' => $p->warehouseId,
                        'available' => $p->stock - $p->dailyRate * $this->keepDays,
                        'given' => 0,
                        'reason' => TransferDonorReason::Turnover,
                        'coverageBefore' => $coverage,
                        'coverageAfter' => static fn (int $given): float => ($p->stock - $given) / $p->dailyRate,
                    ];
                }
            }
            foreach ($surplusByProduct[$productId] ?? [] as $donor) {
                $donors[] = [
                    'warehouseId' => $donor->warehouseId,
                    'available' => $donor->available,
                    'given' => 0,
                    'reason' => TransferDonorReason::StockSurplus,
                    'coverageBefore' => INF,
                    'coverageAfter' => static fn (int $given): float => INF,
                ];
            }

            // Ничья — побайтово (strcmp), а не <=>: '10' <=> '9' сравнивалось бы как числа.
            usort($deficits, static fn (array $a, array $b) => $a[1] <=> $b[1] ?: strcmp($a[0]->warehouseId, $b[0]->warehouseId));
            $deficitPairs += count($deficits);

            foreach ($deficits as [$to, $toCoverage]) {
                $need = $to->dailyRate * $this->targetDays - $to->stock;
                $received = 0;
                $rows = 0;

                usort($donors, static fn (array $a, array $b) => $b['available'] <=> $a['available']
                    ?: strcmp($a['warehouseId'], $b['warehouseId']));

                foreach ($donors as &$donor) {
                    if ($need < $this->minQuantity) {
                        break;
                    }
                    $quantity = (int) floor(min($need, $donor['available']));
                    if ($quantity < $this->minQuantity) {
                        continue;
                    }

                    $donor['available'] -= $quantity;
                    $donor['given'] += $quantity;
                    $need -= $quantity;
                    $received += $quantity;
                    $rows++;

                    $recommendations[] = new TransferRecommendation(
                        $to->productId,
                        $donor['warehouseId'],
                        $to->warehouseId,
                        $quantity,
                        $donor['coverageBefore'],
                        ($donor['coverageAfter'])($donor['given']),
                        $toCoverage,
                        ($to->stock + $received) / $to->dailyRate,
                        $to->dailyRate,
                        $donor['reason'],
                    );
                }
                unset($donor);

                if ($rows === 0) {
                    $unmatched++;
                }
            }
        }

        usort($recommendations, static fn (TransferRecommendation $a, TransferRecommendation $b) => $a->toCoverageBefore <=> $b->toCoverageBefore
            ?: strcmp($a->productId, $b->productId)
            ?: strcmp($a->toWarehouseId, $b->toWarehouseId)
            ?: strcmp($a->fromWarehouseId, $b->fromWarehouseId));

        return new TransferPlan($recommendations, $deficitPairs, $unmatched);
    }
}
