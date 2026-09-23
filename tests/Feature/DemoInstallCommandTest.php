<?php

use App\Models\MetricsSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['analytics.mock.profile' => 'small', 'analytics.mock.seed' => 1]);
});

it('syncs references and calculates the whole mock history without creating a user by default', function () {
    $this->artisan('demo:install')
        ->expectsOutputToContain('период 2025-09:2026-08')
        ->assertExitCode(0);

    expect(MetricsSnapshot::query()->distinct()->pluck('metric_key')->sort()->values()->all())
        ->toBe(['abc_xyz_classification', 'avg_check', 'days_of_stock', 'days_since_last_sale', 'lost_sales', 'revenue', 'sales_amount', 'sales_count', 'sales_per_active_day', 'share_of_total', 'stock_no_demand', 'trend', 'turnover'])
        ->and(MetricsSnapshot::where('metric_key', 'revenue')->distinct()->count('period_start'))->toBe(12)
        ->and(User::count())->toBe(0);
});

it('creates a user only with --email and asks for the password interactively', function () {
    $this->artisan('demo:install', ['--email' => 'Demo@Example.com'])
        ->expectsQuestion('Пароль (минимум 12 символов)', 'correct-horse-battery')
        ->expectsQuestion('Повторите пароль', 'correct-horse-battery')
        ->assertExitCode(0);

    expect(User::where('email', 'demo@example.com')->exists())->toBeTrue();
});

it('does not touch an existing user and does not ask for a password', function () {
    $user = User::factory()->create(['email' => 'demo@example.com']);
    $hash = $user->password;

    $this->artisan('demo:install', ['--email' => 'demo@example.com'])
        ->expectsOutputToContain('уже существует')
        ->assertExitCode(0);

    expect($user->fresh()->password)->toBe($hash);
});

it('is idempotent: a second run leaves the same number of snapshots', function () {
    $this->artisan('demo:install')->assertExitCode(0);
    $first = MetricsSnapshot::count();
    $this->artisan('demo:install')->assertExitCode(0);

    expect(MetricsSnapshot::count())->toBe($first);
});

it('refuses to run with a source other than mock', function () {
    config(['analytics.source' => 'bitrix24']);

    $this->artisan('demo:install')->expectsOutputToContain('только при analytics.source=mock')->assertExitCode(1);

    expect(MetricsSnapshot::count())->toBe(0);
});

it('tells to run migrate when tables are missing and does not migrate itself', function () {
    Schema::drop('staging_warehouses');

    $this->artisan('demo:install')
        ->expectsOutputToContain('php artisan migrate')
        ->assertExitCode(1);

    expect(Schema::hasTable('staging_warehouses'))->toBeFalse();
});
