<?php

namespace App\Core\Widgets\DTO;

final readonly class SeriesPoint
{
    public function __construct(
        public string $label,
        public float $value,
    ) {}
}
