<?php

namespace App\Core\Widgets\DTO;

/**
 * Переключатель месяцев страницы: ключи периодов ('month:Y-m'). current —
 * показанный месяц (null — данных нет), previous/next — ближайшие месяцы с
 * данными метрик страницы (null — дальше данных нет).
 */
final readonly class MonthNavigation
{
    public function __construct(
        public ?string $current,
        public ?string $previous,
        public ?string $next,
    ) {}

    /** Переключателя нет: странице нечего показывать. */
    public static function none(): self
    {
        return new self(null, null, null);
    }
}
