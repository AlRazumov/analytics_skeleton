<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Analytics\Sellers\SellerSalesData;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\ComparisonBase;
use App\Core\Domain\Enums\Direction;
use App\Core\Domain\Enums\SellerCoverage;
use App\Core\Domain\Period;
use App\Core\Widgets\ProductTablesProvider;
use App\Core\Widgets\TopNProvider;
use App\Models\MetricsSnapshot;
use App\Models\User;
use App\Repositories\EloquentMetricsSnapshotWriter;
use App\Sync\ReferenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

function runSellerPipeline(SellerCoverage $mode): void
{
    $adapter = new MockAdapter(MockDataProfile::Small, 42, sellerCoverage: $mode);
    app(ReferenceSyncService::class)->sync($adapter);
    $records = app(MetricsCalculationService::class)->calculate(
        $adapter,
        new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
    );
    (new EloquentMetricsSnapshotWriter)->write($records);
}

it('ranks the top 3 sellers by sales_count and keeps "Без продавца" out of the ranking', function () {
    runSellerPipeline(SellerCoverage::Full);

    $data = app(TopNProvider::class)->topN('seller', 'sales_count', Period::fromKey('month:2026-08'), 3, ['sales_count', 'sales_amount', 'share_of_total']);

    expect(array_map(fn ($r) => $r->id, $data->rows))->toBe(['seller-1', 'seller-2', 'seller-3'])
        ->and($data->rows[0]->name)->toBe('Продавец 1')
        ->and($data->rows[0]->extras)->toHaveKeys(['sales_amount', 'share_of_total'])
        ->and($data->unassigned->name)->toBe('Без продавца')
        ->and(array_map(fn ($r) => $r->id, $data->rows))->not->toContain(SellerSalesData::NO_SELLER)
        ->and($data->total)->toBe(11); // 12 продавцов, у уволенного в августе нет продаж
});

it('renders the block on the overview in full mode without the coverage notice', function () {
    runSellerPipeline(SellerCoverage::Full);

    $this->get('/dashboards/overview')->assertOk()
        ->assertSee('widget-top-n-seller', false)
        ->assertSee('Продавец 1')->assertSee('Без продавца')->assertSee('Количество продаж')->assertSee('Сумма продаж')
        ->assertDontSee('покрывают');
});

it('shows the coverage notice with a percentage in partial mode', function () {
    runSellerPipeline(SellerCoverage::Partial);

    $html = $this->get('/dashboards/overview')->assertOk()->assertSee('widget-top-n-seller', false)->getContent();

    expect(preg_match('/покрывают (\d+)% продаж/u', $html, $m))->toBe(1)
        ->and((int) $m[1])->toBeBetween(55, 75);
});

it('does not render the block at all in none mode', function () {
    runSellerPipeline(SellerCoverage::None);

    $this->get('/dashboards/overview')->assertOk()
        ->assertDontSee('widget-top-n-seller', false)->assertDontSee('Без продавца')->assertDontSee('покрывают');
});

it('hides the block when the sellers feature flag is off', function () {
    runSellerPipeline(SellerCoverage::Full);
    config(['analytics.features.sellers' => false]);

    $this->get('/dashboards/overview')->assertOk()->assertDontSee('widget-top-n-seller', false);
});

it('does not show a metric that is disabled in config', function () {
    runSellerPipeline(SellerCoverage::Full);
    config(['analytics.enabled_metrics.seller' => ['sales_count', 'share_of_total']]);

    $this->get('/dashboards/overview')->assertOk()
        ->assertSee('widget-top-n-seller', false)->assertSee('Количество продаж')->assertDontSee('Сумма продаж');

    config(['analytics.enabled_metrics.seller' => ['sales_amount']]);
    $this->get('/dashboards/overview')->assertOk()->assertDontSee('widget-top-n-seller', false);
});

it('does not calculate metrics that are disabled in config', function () {
    config(['analytics.enabled_metrics.seller' => ['sales_count']]);
    runSellerPipeline(SellerCoverage::Full);

    expect(MetricsSnapshot::where('entity_type', 'seller')->distinct()->pluck('metric_key')->all())->toBe(['sales_count']);
});

it('keeps the generic top-N consistent with the product top (regression)', function () {
    runSellerPipeline(SellerCoverage::Full);
    $period = Period::fromKey('month:2026-08');

    $product = app(ProductTablesProvider::class)->topProducts($period, Direction::Desc, 5, ComparisonBase::Previous);
    $generic = app(TopNProvider::class)->topN('product', 'revenue', $period, 5);

    expect(array_map(fn ($r) => $r->productId, $product->rows))->toBe(array_map(fn ($r) => $r->id, $generic->rows))
        ->and($generic->unassigned)->toBeNull()->and($generic->coverage)->toBeNull();

    $this->get('/dashboards/top-products')->assertOk()->assertSee('Топ товаров по выручке');
});
