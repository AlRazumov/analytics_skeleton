<?php

use Illuminate\Support\Facades\Schedule;

// Ночной пересчёт метрик. withoutOverlapping — блокировка в кэше (БД), не
// даёт запустить второй расчёт поверх идущего; истекает через 3 часа, если
// процесс упал, не сняв её.
$metricsAt = (string) config('analytics.schedule.metrics_at');
if ($metricsAt !== '') {
    Schedule::command('metrics:calculate')
        ->dailyAt($metricsAt)
        ->withoutOverlapping(180)
        ->appendOutputTo(storage_path('logs/metrics.log'));
}
