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

    /** Запуск в статусе running дольше этого срока считается оборвавшимся (как и срок блокировки планировщика). */
    public const int ABANDONED_AFTER_HOURS = 3;

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

    /**
     * Запуски, застрявшие в running (процесс убит, например по OOM, и не
     * успел записать статус), помечаются failed — иначе плашка свежести
     * показывала бы «Идёт расчёт» до следующего запуска.
     */
    public static function failAbandoned(CarbonImmutable $now): void
    {
        self::query()
            ->where('status', self::RUNNING)
            ->where('started_at', '<', $now->subHours(self::ABANDONED_AFTER_HOURS))
            ->update([
                'status' => self::FAILED,
                'error' => 'Процесс расчёта оборвался, не сообщив о завершении.',
                'finished_at' => $now,
            ]);
    }
}
