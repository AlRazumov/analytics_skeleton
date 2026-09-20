<?php

namespace App\Core\Transfers;

/**
 * Результат планирования. $deficitPairs — число дефицитных пар (товар ×
 * склад); $unmatchedDeficits — из них без единой рекомендации (нет донора
 * либо объём меньше минимальной партии).
 */
final readonly class TransferPlan
{
    /**
     * @param  list<TransferRecommendation>  $recommendations
     */
    public function __construct(
        public array $recommendations,
        public int $deficitPairs,
        public int $unmatchedDeficits,
    ) {}
}
