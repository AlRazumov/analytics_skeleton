<?php

namespace App\Console\Commands;

use App\Adapters\DataSourceAdapterFactory;
use App\Adapters\Mock\MockDataProfile;
use App\Console\Commands\Concerns\ResolvesMonthRange;
use App\Core\Analytics\CategoryRevenueCalculator;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Models\MetricsRun;
use App\Models\MetricsSnapshot;
use App\Sync\ReferenceSyncService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

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

    protected $description = 'Пересчитать метрики (revenue, ABC/XYZ, turnover, категории) из DataSourceAdapter в metrics_snapshots';

    /** Пары [entity_type, metric_key], которые пересчитываются (и удаляются перед записью). */
    private const array METRICS = [
        ['product', 'revenue'],
        ['product', 'abc_xyz_classification'],
        ['product', 'turnover'],
        ['product', 'lost_sales'],
        [CategoryRevenueCalculator::ENTITY_TYPE, CategoryRevenueCalculator::METRIC_KEY],
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
        $this->raiseMemoryLimit((string) config('analytics.calculate_memory_limit'));

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

        // Журнал запусков (индикатор свежести на страницах) пишется вне
        // транзакции расчёта: упавший запуск остаётся в журнале со статусом failed.
        $run = MetricsRun::query()->create([
            'source' => $factory->source().($profileOption !== '' ? "/{$profileOption}" : ''),
            'status' => MetricsRun::RUNNING,
            'period_start' => $dateRange->start->format('Y-m-d'),
            'period_end' => $dateRange->end->format('Y-m-d'),
            'started_at' => now(),
        ]);

        try {
            // Справочники — из того же адаптера, чтобы страницы показывали названия без обращений к источнику.
            $counts = $referenceSync->sync($adapter);
            $this->info("Справочники: товаров — {$counts['products']}, складов — {$counts['warehouses']}, продавцов — {$counts['sellers']}.");

            // Удаление и запись — одной транзакцией: сбой расчёта или записи не
            // оставляет месяцы пустыми или обрезанными. Записи пишутся порциями по
            // мере расчёта, чтобы не держать весь прогон в памяти.
            $written = DB::transaction(function () use ($service, $adapter, $dateRange, $writer): int {
                $this->deleteExistingSnapshots($dateRange);
                $written = 0;
                foreach ($service->calculateInChunks($adapter, $dateRange) as $chunk) {
                    $writer->write($chunk);
                    $written += count($chunk);
                }

                return $written;
            });
        } catch (Throwable $e) {
            $run->update(['status' => MetricsRun::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);

            throw $e;
        }

        $run->update(['status' => MetricsRun::SUCCESS, 'snapshots_written' => $written, 'finished_at' => now()]);
        $this->info(sprintf('Готово: записано снэпшотов — %d.', $written));

        return self::SUCCESS;
    }

    /**
     * Поднимает memory_limit до $minimum, если текущий ниже; неограниченный
     * (-1) и больший лимит не трогает. Нужен, потому что schedule:run
     * запускает команду отдельным процессом без `-d memory_limit`.
     */
    private function raiseMemoryLimit(string $minimum): void
    {
        $current = (string) ini_get('memory_limit');
        if ($minimum === '' || $current === '-1') {
            return;
        }

        if (ini_parse_quantity($current) < ini_parse_quantity($minimum)) {
            ini_set('memory_limit', $minimum);
        }
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
