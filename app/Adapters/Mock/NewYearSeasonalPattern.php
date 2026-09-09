<?php

namespace App\Adapters\Mock;

/**
 * Новогодний пик спроса (ноябрь-декабрь-январь). Выбран как самый
 * узнаваемый и общеприменимый сезонный паттерн ритейла/B2B-продаж —
 * не завязан на специфику конкретной отрасли клиента, поэтому одинаково
 * уместен и для 1С-, и для Bitrix24-направления на этом срезе.
 *
 * Множители ОРИЕНТИРОВОЧНЫЕ, подлежат калибровке при появлении реальных
 * данных (см. докблок MockDataProfile).
 */
final class NewYearSeasonalPattern implements SeasonalPattern
{
    public function multiplierForMonth(int $month): float
    {
        return match ($month) {
            11 => 1.3,
            12 => 1.8,
            1 => 1.2,
            default => 1.0,
        };
    }
}
