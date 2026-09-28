<?php

use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Models\MetricsRun;
use App\Models\MetricsSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function metricsRun(string $status, string $startedAt, ?string $finishedAt = null, string $from = '2025-09-01', string $to = '2026-08-31'): MetricsRun
{
    return MetricsRun::query()->create([
        'source' => 'mock', 'status' => $status, 'period_start' => $from, 'period_end' => $to,
        'started_at' => $startedAt, 'finished_at' => $finishedAt,
    ]);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('logs a successful metrics:calculate run with its source, period and snapshot count', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-07:2026-08'])->assertExitCode(0);

    $run = MetricsRun::query()->sole();
    expect($run->status)->toBe(MetricsRun::SUCCESS)
        ->and($run->source)->toBe('mock/small')
        ->and($run->period_start->format('Y-m-d'))->toBe('2026-07-01')
        ->and($run->period_end->format('Y-m-d'))->toBe('2026-08-31')
        ->and($run->snapshots_written)->toBe(MetricsSnapshot::query()->count())
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBeNull();
});

it('keeps a failed run in the log although the calculation itself is rolled back', function () {
    app()->instance(MetricsSnapshotWriter::class, new class implements MetricsSnapshotWriter
    {
        public function write(array $records): void
        {
            throw new RuntimeException('write failed');
        }
    });

    expect(fn () => $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])->run())
        ->toThrow(RuntimeException::class, 'write failed');

    $run = MetricsRun::query()->sole();
    expect($run->status)->toBe(MetricsRun::FAILED)
        ->and($run->error)->toBe('write failed')
        ->and($run->finished_at)->not->toBeNull();
});

it('does not log a run rejected before it starts', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'nope'])->assertExitCode(1);

    expect(MetricsRun::query()->count())->toBe(0);
});

it('shows when and for which period the data was calculated, in the display time zone', function () {
    CarbonImmutable::setTestNow('2026-09-28 10:00:00');
    config(['analytics.display.timezone' => 'Europe/Moscow']);
    metricsRun(MetricsRun::FAILED, '2026-09-26 00:00:00', '2026-09-26 00:01:00');
    metricsRun(MetricsRun::SUCCESS, '2026-09-28 03:00:00', '2026-09-28 03:02:00');
    $this->actingAs(User::factory()->create());

    $this->get('/dashboards/top-products')->assertOk()
        ->assertSee('Данные рассчитаны 28.09.2026 06:02 за 2025-09 — 2026-08.')
        // Упавшая попытка раньше успешного расчёта уже не важна.
        ->assertDontSee('завершился ошибкой')
        ->assertDontSee('class="freshness-bar freshness-bar--warning"', false);
});

it('warns when the latest attempt failed after the last successful run', function () {
    CarbonImmutable::setTestNow('2026-09-29 10:00:00');
    metricsRun(MetricsRun::SUCCESS, '2026-09-28 03:00:00', '2026-09-28 03:02:00');
    metricsRun(MetricsRun::FAILED, '2026-09-29 03:00:00', '2026-09-29 03:01:00');
    $this->actingAs(User::factory()->create());

    $this->get('/dashboards/overview')->assertOk()
        ->assertSee('Данные рассчитаны 28.09.2026 06:02')
        ->assertSee('Последний расчёт (29.09.2026 06:00) завершился ошибкой — показаны данные предыдущего.')
        ->assertSee('class="freshness-bar freshness-bar--warning"', false);
});

it('says a calculation is running, and warns when the data is older than the threshold', function () {
    CarbonImmutable::setTestNow('2026-09-30 10:00:00');
    config(['analytics.display.stale_after_hours' => 36]);
    metricsRun(MetricsRun::SUCCESS, '2026-09-28 03:00:00', '2026-09-28 03:02:00');
    metricsRun(MetricsRun::RUNNING, '2026-09-30 09:00:00');
    $this->actingAs(User::factory()->create());

    $this->get('/dashboards/stock')->assertOk()
        ->assertSee('Идёт расчёт (начат 30.09.2026 12:00).')
        ->assertSee('Данные старше 36 ч — проверьте ночной расчёт.')
        ->assertSee('class="freshness-bar freshness-bar--warning"', false);
});

it('shows nothing without a run log and on the login page', function () {
    $this->get('/login')->assertOk()->assertDontSee('class="freshness-bar', false);

    $this->actingAs(User::factory()->create());
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('class="freshness-bar', false);

    metricsRun(MetricsRun::SUCCESS, '2026-09-28 03:00:00', '2026-09-28 03:02:00');
    $this->get('/dashboards/overview')->assertOk()->assertSee('class="freshness-bar', false);
});
