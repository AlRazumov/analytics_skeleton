<?php

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Models\MetricsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InMemoryAdapter;

uses(RefreshDatabase::class);

it('passes the configured mock source and writes nothing', function () {
    config(['analytics.source' => 'mock', 'analytics.mock.profile' => 'small', 'analytics.mock.seed' => 1]);

    $this->artisan('adapter:check')
        ->expectsOutputToContain('источник=mock, период=2026-06-01..2026-08-31')
        ->expectsOutputToContain('Нарушений нет.')
        ->assertExitCode(0);

    expect(MetricsSnapshot::count())->toBe(0);
});

it('lists violations with examples and fails for a broken source', function () {
    app()->instance(DataSourceAdapter::class, new class extends InMemoryAdapter
    {
        public function fetchDeals(DateRange $period): iterable
        {
            return $this->deals;
        }
    });

    $this->artisan('adapter:check --period=2026-07:2026-07')
        ->expectsOutputToContain('период=2026-07-01..2026-07-31')
        ->expectsOutputToContain('[deals.range] сделка вне запрошенного диапазона 2026-07-01..2026-07-31 (случаев: 10)')
        ->expectsOutputToContain('пропущено — history.bounds')
        ->assertExitCode(1);
});

it('defaults to the last three months for a source without history bounds', function () {
    app()->instance(DataSourceAdapter::class, new InMemoryAdapter);
    $now = new DateTimeImmutable('first day of this month');

    $this->artisan('adapter:check')
        ->expectsOutputToContain('период='.$now->modify('-2 months')->format('Y-m-d').'..'.$now->modify('last day of this month')->format('Y-m-d'))
        ->assertExitCode(0);
});

it('rejects a malformed period', function () {
    $this->artisan('adapter:check --period=2026-07')
        ->expectsOutputToContain("Ожидается 'YYYY-MM:YYYY-MM'")
        ->assertExitCode(1);
});
