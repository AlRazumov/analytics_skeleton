<?php

namespace App\Services;

use App\Core\Transfers\TransferPlan;

/**
 * Результат сервиса: план + счётчики чтения. $positionsRead — позиций
 * передано планировщику; $skippedWithoutMeta — строк days_of_stock без
 * value_meta.stock_qty/daily_rate (пропущены).
 */
final readonly class TransferRecommendationResult
{
    public function __construct(
        public TransferPlan $plan,
        public int $positionsRead,
        public int $skippedWithoutMeta,
    ) {}
}
