<?php

namespace App\Core\Widgets\DTO;

final readonly class KpiCardData
{
    public function __construct(
        public string $label,
        public float $value,
        public ?float $deltaPercent = null,
        public ?string $unit = null,
    ) {}
}
