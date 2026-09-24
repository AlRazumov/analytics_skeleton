<?php

namespace App\Console\Commands;

use App\Adapters\DataSourceAdapterFactory;
use App\Adapters\Mock\MockDataProfile;
use App\Console\Commands\Concerns\ResolvesMonthRange;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Models\MetricsSnapshot;
use App\Sync\ReferenceSyncService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Прогоняет расчётный пайплайн (core/Analytics) по адаптеру источника
 * (analytics.source, контейнер) и пишет результат в metrics_snapshots
 * через MetricsSnapshotWriter. Расчётная логика принимает только контракт
 * DataSourceAdapter. --profile — переопределение профиля мока, допустимо
 * только при source=mock.
 */
class CalculateMetrics extends Command
{
    use ResolvesMonthRange;

    protected $signature = 'metrics:calculate {--profile= : Профиль мока (только при analytics.source=mock)} {--period=}';

    protected $description = 'Пересчитать метрики (revenue, ABC/XYZ, turnover) из DataSourceAdapter в metrics_snapshots';

    /** Пары [entity_type, metric_key], которые пересчитываются (и удаляются перед записью). */
    private const array METRICS = [
        ['product', 'revenue'],
        ['product', 'abc_xyz_classification'],
        ['product', 'turnover'],
        ['product', 'lost_sales'],
        ['seller', 'sales_count'],
        ['seller', 'sales_amount'],
        ['seller', 'avg_check'],
        ['seller', 'share_of_total'],
        ['seller', 'sales_per_active_day'],
        ['seller', 'trend'],
        [DeadStockCalculator::ENTITY_TYPE, DeadStockCalculator::METRIC_KEY],
        [DaysOfStockCalculator::ENTITY_TYPE, DaysOfStockCalculator::METRIC_KEY],
        [DaysOfStockCalculator::ENTITY_TYPE, DaysOfStockCalculator::NO_DEMAND_STOCK_METRIC_KEY],
    ];

    public function handle(MetricsCalculationService $service, MetricsSnapshotWriter $writer, DataSourceAdapterFactory $factory, ReferenceSyncService $referenceSync): int
    {
        try {
            $profileOption = (string) $this->option('profile');
            if ($profileOption !== '' && $factory->source() !== 'mock') {
                throw new InvalidArgumentException("--profile допустим только при analytics.source=mock (сейчас '{$factory->source()}').");
            }

            $adapter = $profileOption !== ''
                ? $factory->make($this->resolveProfile($profileOption))
                : app(DataSourceAdapter::class);
            $dateRange = $this->resolvePeriod($this->option('period'), $adapter);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Расчёт метрик: источник=%s, период=%s..%s',
            $factory->source().($profileOption !== '' ? "/{$profileOption}" : ''),
            $dateRange->start->format('Y-m-d'),
            $dateRange->end->format('Y-m-d'),
        ));

        // Справочники — из того же адаптера, чтобы страницы показывали названия без обращений к источнику.
        $counts = $referenceSync->sync($adapter);
        $this->info("Справочники: товаров — {$counts['products']}, складов — {$counts['warehouses']}, продавцов — {$counts['sellers']}.");

        $records = $service->calculate($adapter, $dateRange);

        $this->deleteExistingSnapshots($dateRange);
        $writer->write($records);

        $this->info(sprintf('Готово: записано снэпшотов — %d.', count($records)));

        return self::SUCCESS;
    }

    private function resolveProfile(string $value): MockDataProfile
    {
        $profile = MockDataProfile::tryFrom($value);

        if ($profile === null) {
            $allowed = implode('|', array_map(static fn (MockDataProfile $p) => $p->value, MockDataProfile::cases()));

            throw new InvalidArgumentException("Неверный --profile='{$value}'. Допустимые значения: {$allowed}.");
        }

        return $profile;
    }

    private function resolvePeriod(?string $value, DataSourceAdapter $adapter): DateRange
    {
        return $this->monthRange($value, $adapter, 12);
    }

    private function deleteExistingSnapshots(DateRange $dateRange): void
    {
        $cursor = new DateTimeImmutable($dateRange->start->format('Y-m-01'));
        $last = new DateTimeImmutable($dateRange->end->format('Y-m-01'));

        $periods = [];
        while ($cursor <= $last) {
            $periods[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 month');
        }

        MetricsSnapshot::query()
            ->where(function ($query) {
                foreach (self::METRICS as [$entityType, $metricKey]) {
                    $query->orWhere(fn ($q) => $q->where('entity_type', $entityType)->where('metric_key', $metricKey));
                }
            })
            ->where('period_type', 'month')
            ->whereIn('period_start', $periods)
            ->delete();
    }
}
