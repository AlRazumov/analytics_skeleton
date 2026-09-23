<?php

namespace App\Core\Widgets\DTO;

/** Строка топ-N: значение ранжирующей метрики и дополнительные колонки (metric_key => значение). */
final readonly class TopNRow
{
    /**
     * @param  array<string, float>  $extras
     */
    public function __construct(
        public string $id,
        public string $name,
        public float $value,
        public array $extras = [],
    ) {}
}
