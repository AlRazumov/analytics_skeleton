<?php

use App\Core\Analytics\MetricsCalculationService;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Widgets\DTO\MetricsSnapshotRecord;

it('merges AbcClassifier and XyzClassifier output into one abc_xyz_classification row per product', function () {
    // prod-1: 700 из 1000 суммарной выручки (кумулятивная доля 0.7 <=
    // 0.8 -> A), одинаковый спрос в оба месяца (CV=0 -> X).
    // prod-2: 300 из 1000 (кумулятивная доля 1.0 -> C), спрос только в
    // одном из двух месяцев (-> Z).
    $deals = [
        new Deal('d-1', 'prod-1', 350.0, new DateTimeImmutable('2026-01-05')),
        new Deal('d-2', 'prod-1', 350.0, new DateTimeImmutable('2026-02-05')),
        new Deal('d-3', 'prod-2', 300.0, new DateTimeImmutable('2026-01-10')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-28'));

    $records = (new MetricsCalculationService)->calculate(fakeAdapter($deals), $period);

    $classifications = collect($records)->where('metricKey', 'abc_xyz_classification');

    // Одна строка на товар (abc_class и xyz_class слиты в одном
    // value_meta), а не две конкурирующие записи.
    expect($classifications)->toHaveCount(2);

    $byId = $classifications->keyBy('entityId');

    expect($byId['prod-1']->valueMeta)->toBe(['abc_class' => 'A', 'xyz_class' => 'X']);
    expect($byId['prod-2']->valueMeta)->toBe(['abc_class' => 'C', 'xyz_class' => 'Z']);

    // value итоговой строки берётся от AbcClassifier (суммарная
    // выручка товара за период), а не от XyzClassifier (CV) — см.
    // допущение №6 в отчёте stage-04.
    expect($byId['prod-1']->value)->toBe(700.0);
    expect($byId['prod-2']->value)->toBe(300.0);

    // period итоговой строки — тоже от AbcClassifier (инвариант,
    // задокументированный в mergeAbcXyz()): оба классификатора кладут
    // period = последний месяц периода, поэтому здесь это неразличимо,
    // но зафиксировано явно.
    expect($byId['prod-1']->period)->toBe('month:2026-02');
});

it('calls fetchDeals() and fetchStockMovements() exactly once per calculate(), not once per calculator', function () {
    // Регрессионный тест против исходной проблемы (см. docs/roadmap.md
    // и docs/reports/stage-04-report.md): раньше fetchDeals()
    // вызывался трижды (RevenueByPeriodCalculator, AbcClassifier,
    // XyzClassifier дергали адаптер независимо), а fetchStockMovements()
    // — отдельно из TurnoverCalculator. Проверяем не "результат тот же",
    // а сам факт единственного вызова каждого fetch*-метода.
    $deals = [
        new Deal('d-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-05')),
    ];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $adapter = fakeAdapter($deals);

    (new MetricsCalculationService)->calculate($adapter, $period);

    expect($adapter->fetchDealsCalls)->toBe(1);
    expect($adapter->fetchStockMovementsCalls)->toBe(1);
});

it('throws when ABC and XYZ records for the same entityId disagree on period', function () {
    // Инвариант mergeAbcXyz(): оба классификатора всегда кладут
    // одинаковый period для одного товара. Реальные AbcClassifier и
    // XyzClassifier это гарантируют, поэтому расхождение здесь
    // собирается вручную через reflection — единственный способ
    // проверить defensive-ветку без переписывания самих
    // классификаторов на разную гранулярность.
    $abcRecords = [
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 700.0, 'month:2026-02', ['abc_class' => 'A']),
    ];
    $xyzRecords = [
        new MetricsSnapshotRecord('product', 'prod-1', 'abc_xyz_classification', 0.0, '2026-Q1', ['xyz_class' => 'X']),
    ];

    $service = new MetricsCalculationService;
    $merge = new ReflectionMethod($service, 'mergeAbcXyz');

    expect(fn () => $merge->invoke($service, $abcRecords, $xyzRecords))
        ->toThrow(RuntimeException::class, "entityId='prod-1'");
});

it('skips stock movements and turnover when the adapter does not report the StockMovements capability', function () {
    $deals = [new Deal('d-1', 'prod-1', 100.0, new DateTimeImmutable('2026-01-05'))];
    $period = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    $adapter = fakeAdapter($deals, capabilities: []);

    $records = (new MetricsCalculationService)->calculate($adapter, $period);

    expect($adapter->fetchStockMovementsCalls)->toBe(0)
        ->and(collect($records)->where('metricKey', 'turnover'))->toHaveCount(0)
        ->and(collect($records)->where('metricKey', 'revenue'))->toHaveCount(1);
});
