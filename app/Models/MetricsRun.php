<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Запуск metrics:calculate (журнал для индикатора свежести данных).
 *
 * @property int $id
 * @property string $source
 * @property string $status running | success | failed
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int|null $snapshots_written
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class MetricsRun extends Model
{
    public const string RUNNING = 'running';

    public const string SUCCESS = 'success';

    public const string FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'source',
        'status',
        'period_start',
        'period_end',
        'snapshots_written',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'snapshots_written' => 'integer',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];
}
