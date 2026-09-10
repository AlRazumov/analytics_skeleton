<?php

namespace App\Core\Widgets\DTO;

/**
 * Переиспользуется и для line, и для bar chart — отличие только в
 * Blade-компоненте/типе Chart.js, не в форме данных.
 */
final readonly class LineChartData
{
    /**
     * @param  Series[]  $series
     */
    public function __construct(
        public string $title,
        public array $series,
    ) {}
}
