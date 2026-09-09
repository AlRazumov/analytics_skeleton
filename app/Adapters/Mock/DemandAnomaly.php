<?php

namespace App\Adapters\Mock;

use DateTimeImmutable;

/**
 * Точечная аномалия спроса на конкретный товар в конкретный период.
 * Заготовка для расширения: сейчас есть один вид (разовый всплеск,
 * см. SingleSpikeAnomaly), позже можно добавить провал или другой вид
 * через новую реализацию интерфейса, не трогая существующие.
 */
interface DemandAnomaly
{
    /**
     * @return float|null множитель к обычному спросу, либо null, если
     *                    аномалия не затрагивает этот товар/дату
     */
    public function multiplierFor(string $productId, DateTimeImmutable $date): ?float;
}
