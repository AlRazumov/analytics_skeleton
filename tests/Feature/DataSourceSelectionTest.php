<?php

use App\Adapters\DataSourceAdapterFactory;
use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Contracts\DataSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the mock adapter by default', function () {
    expect(config('analytics.source'))->toBe('mock')
        ->and(app(DataSourceAdapter::class))->toBeInstanceOf(MockAdapter::class);
});

it('takes the mock profile and seed from the config', function () {
    config(['analytics.mock.profile' => 'small', 'analytics.mock.seed' => 7]);

    $fromContainer = app(DataSourceAdapter::class);
    $expected = new MockAdapter(MockDataProfile::Small, 7);
    $otherSeed = new MockAdapter(MockDataProfile::Small, 8);

    $products = fn ($a) => iterator_to_array($a->fetchProducts(), false);
    expect(count($products($fromContainer)))->toBe(MockDataProfile::Small->productCount())
        ->and($products($fromContainer))->toEqual($products($expected))
        ->and($products($fromContainer))->not->toEqual($products($otherSeed));
});

it('throws for an unknown source with the list of allowed values', function (string $source) {
    config(['analytics.source' => $source]);

    expect(fn () => app(DataSourceAdapter::class))
        ->toThrow(InvalidArgumentException::class, 'Допустимые значения: mock');
})->with(['onec', 'bitrix24', '', 'MOCK']);

it('throws for an invalid configured mock profile', function () {
    config(['analytics.mock.profile' => 'huge']);

    expect(fn () => app(DataSourceAdapterFactory::class)->make())->toThrow(InvalidArgumentException::class, 'small');
});

it('keeps --profile working for metrics:calculate with the mock source', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'small', '--period' => '2026-08:2026-08'])
        ->expectsOutputToContain('источник=mock/small')
        ->assertExitCode(0);
});

it('runs metrics:calculate with the adapter from the config when --profile is omitted', function () {
    config(['analytics.mock.profile' => 'small']);

    $this->artisan('metrics:calculate', ['--period' => '2026-08:2026-08'])->assertExitCode(0);
});

it('rejects --profile when the source is not mock', function () {
    config(['analytics.source' => 'onec']);

    $this->artisan('metrics:calculate', ['--profile' => 'small'])
        ->expectsOutputToContain('--profile допустим только при analytics.source=mock')
        ->assertExitCode(1);
});

it('rejects an invalid --profile', function () {
    $this->artisan('metrics:calculate', ['--profile' => 'huge'])->assertExitCode(1);
});
