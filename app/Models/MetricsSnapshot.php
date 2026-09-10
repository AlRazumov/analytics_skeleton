<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $entity_type
 * @property string $entity_id
 * @property string $metric_key
 * @property float $value
 * @property array|null $value_meta
 * @property string $period
 */
class MetricsSnapshot extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'metric_key',
        'value',
        'value_meta',
        'period',
    ];

    protected $casts = [
        'value' => 'float',
        'value_meta' => 'array',
    ];
}
