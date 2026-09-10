<?php

namespace Database\Seeders;

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Models\MetricsSnapshot;
use DateTimeImmutable;
use Illuminate\Database\Seeder;

/**
 * Наполняет metrics_snapshots демонстрационными данными для
 * /demo/widgets. Это инфраструктурная склейка для демо-страницы, а не
 * этап агрегации метрик — реальный расчётный пайплайн (adapters ->
 * metrics_snapshots) не входит в этап 03 и не появляется в core.
 */
class DemoMetricsSnapshotSeeder extends Seeder
{
    private const string ENTITY_TYPE = 'product';

    private const string REVENUE_METRIC = 'revenue';

    private const string ABC_XYZ_METRIC = 'abc_xyz_classification';

    public function run(): void
    {
        $adapter = new MockAdapter(MockDataProfile::Small, seed: 7);
        $period = new DateRange(
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-06-30'),
        );

        MetricsSnapshot::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->whereIn('metric_key', [self::REVENUE_METRIC, self::ABC_XYZ_METRIC])
            ->delete();

        // revenue по (product, месяц)
        $revenueByProductAndMonth = [];
        foreach ($adapter->fetchDeals($period) as $deal) {
            $month = $deal->date->format('Y-m');
            $revenueByProductAndMonth[$deal->productId][$month] = ($revenueByProductAndMonth[$deal->productId][$month] ?? 0.0) + $deal->amount;
        }

        $rows = [];
        $now = now();
        foreach ($revenueByProductAndMonth as $productId => $byMonth) {
            foreach ($byMonth as $month => $value) {
                $rows[] = [
                    'entity_type' => self::ENTITY_TYPE,
                    'entity_id' => $productId,
                    'metric_key' => self::REVENUE_METRIC,
                    'value' => $value,
                    'value_meta' => null,
                    'period' => $month,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // ABC/XYZ-классификация: ABC по суммарной выручке товара за
        // период, XYZ детерминированно по хэшу id (демо, не настоящий
        // расчёт вариации спроса).
        $totalByProduct = [];
        foreach ($revenueByProductAndMonth as $productId => $byMonth) {
            $totalByProduct[$productId] = array_sum($byMonth);
        }
        arsort($totalByProduct);

        $productIds = array_keys($totalByProduct);
        $count = count($productIds);
        $lastMonth = $period->end->format('Y-m');

        foreach ($productIds as $index => $productId) {
            $rank = $index / max(1, $count - 1);
            $abcClass = match (true) {
                $rank < 0.2 => 'A',
                $rank < 0.5 => 'B',
                default => 'C',
            };
            $xyzClass = ['X', 'Y', 'Z'][crc32($productId) % 3];

            $rows[] = [
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $productId,
                'metric_key' => self::ABC_XYZ_METRIC,
                'value' => $totalByProduct[$productId],
                'value_meta' => json_encode(['abc_class' => $abcClass, 'xyz_class' => $xyzClass]),
                'period' => $lastMonth,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricsSnapshot::query()->insert($chunk);
        }
    }
}
