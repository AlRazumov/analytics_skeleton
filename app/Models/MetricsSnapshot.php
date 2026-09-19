<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $entity_type
 * @property string $entity_id
 * @property string $metric_key
 * @property float $value
 * @property array|null $value_meta
 * @property string $period_type
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 */
class MetricsSnapshot extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'metric_key',
        'value',
        'value_meta',
        'period_type',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'value' => 'float',
        'value_meta' => 'array',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
    ];
}
