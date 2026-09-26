<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

uses(RefreshDatabase::class);

/** @return list<Event> события metrics:calculate после повторной загрузки routes/console.php */
function metricsEvents(): array
{
    $schedule = new Schedule;
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    return array_values(array_filter(
        $schedule->events(),
        fn (Event $e) => str_contains((string) $e->command, 'metrics:calculate'),
    ));
}

it('schedules metrics:calculate daily at the configured time without overlapping', function () {
    config(['analytics.schedule.metrics_at' => '04:30']);

    $events = metricsEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 4 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->output)->toBe(storage_path('logs/metrics.log'));
});

it('does not schedule anything when the time is empty', function () {
    config(['analytics.schedule.metrics_at' => '']);

    expect(metricsEvents())->toBe([]);
});

it('raises the memory limit for metrics:calculate but never lowers it', function (string $before, string $minimum, string $after) {
    $original = ini_get('memory_limit');
    try {
        ini_set('memory_limit', $before);
        config(['analytics.calculate_memory_limit' => $minimum]);

        $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->assertExitCode(0);

        expect(ini_get('memory_limit'))->toBe($after);
    } finally {
        ini_set('memory_limit', $original);
    }
})->with([
    'raised' => ['1G', '2G', '2G'],
    'kept when higher' => ['2G', '512M', '2G'],
    'kept when unlimited' => ['-1', '512M', '-1'],
]);
