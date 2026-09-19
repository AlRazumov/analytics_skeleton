<?php

namespace App\Console\Commands;

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Analytics\DaysOfStockCalculator;
use App\Core\Analytics\DeadStockCalculator;
use App\Core\Analytics\MetricsCalculationService;
use App\Core\Contracts\DataSourceAdapter;
use App\Core\Domain\DateRange;
use App\Core\Widgets\Contracts\MetricsSnapshotWriter;
use App\Models\MetricsSnapshot;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Прогоняет расчётный пайплайн (core/Analytics) по MockAdapter и пишет
 * результат в metrics_snapshots через MetricsSnapshotWriter.
 *
 * MockAdapter инстанцируется здесь напрямую (не через контейнер) —
 * осознанное решение, единственная текущая реализация DataSourceAdapter,
 * реального источника (Bitrix24/1С) ещё нет. Расчётная логика
 * (MetricsCalculationService) ничего об этом не знает и принимает
 * только контракт DataSourceAdapter — при появлении второго адаптера
 * или при переносе в Job меняется только эта команда.
 */
class CalculateMetrics extends Command
{
    protected $signature = 'metrics:calculate {--profile=medium} {--period=}';

    protected $description = 'Пересчитать метрики (revenue, ABC/XYZ, turnover) из DataSourceAdapter в metrics_snapshots';

    /** Пары [entity_type, metric_key], которые пересчитываются (и удаляются перед записью). */
    private const array METRICS = [
        ['product', 'revenue'],
        ['product', 'abc_xyz_classification'],
        ['product', 'turnover'],
        [DeadStockCalculator::ENTITY_TYPE, DeadStockCalculator::METRIC_KEY],
        [DaysOfStockCalculator::ENTITY_TYPE, DaysOfStockCalculator::METRIC_KEY],
    ];

    public function handle(MetricsCalculationService $service, MetricsSnapshotWriter $writer): int
    {
        try {
            $profile = $this->resolveProfile((string) $this->option('profile'));
            $adapter = new MockAdapter($profile);
            $dateRange = $this->resolvePeriod($this->option('period'), $adapter);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Расчёт метрик: профиль=%s, период=%s..%s',
            $profile->value,
            $dateRange->start->format('Y-m-d'),
            $dateRange->end->format('Y-m-d'),
        ));

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
        if ($value === null || $value === '') {
            // Мок отдаёт данные только до historyEnd() (фиксирован ради
            // детерминированности), поэтому по умолчанию берём 12 месяцев,
            // заканчивающихся на нём, а не на «сегодня». Для других адаптеров —
            // 12 месяцев, заканчивающихся текущим.
            if ($adapter instanceof MockAdapter) {
                $end = $adapter->historyEnd();

                return new DateRange(
                    (new DateTimeImmutable($end->format('Y-m-01')))->modify('-11 months'),
                    $end,
                );
            }

            $now = new DateTimeImmutable('first day of this month');

            return new DateRange($now->modify('-11 months'), $now->modify('last day of this month'));
        }

        if (! preg_match('/^(\d{4}-\d{2}):(\d{4}-\d{2})$/', $value, $matches)) {
            throw new InvalidArgumentException("Неверный формат --period='{$value}'. Ожидается 'YYYY-MM:YYYY-MM'.");
        }

        try {
            $start = new DateTimeImmutable($matches[1].'-01');
            $end = new DateTimeImmutable($matches[2].'-01');
        } catch (\Exception) {
            throw new InvalidArgumentException("Неверный формат --period='{$value}'. Ожидается 'YYYY-MM:YYYY-MM'.");
        }

        if ($start > $end) {
            throw new InvalidArgumentException("Неверный --period='{$value}': начало периода позже конца.");
        }

        return new DateRange($start, $end->modify('last day of this month'));
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
