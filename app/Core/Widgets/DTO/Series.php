<?php

namespace App\Core\Widgets\DTO;

final readonly class Series
{
    /**
     * @param  SeriesPoint[]  $points
     */
    public function __construct(
        public string $name,
        public array $points,
    ) {}
}
