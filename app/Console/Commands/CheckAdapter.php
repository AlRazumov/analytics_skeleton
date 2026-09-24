<?php

namespace App\Console\Commands;

use App\Adapters\Contract\AdapterContractChecker;
use App\Adapters\DataSourceAdapterFactory;
use App\Console\Commands\Concerns\ResolvesMonthRange;
use App\Core\Contracts\DataSourceAdapter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Проверка текущего источника (analytics.source) на контракт
 * DataSourceAdapter — см. docs/adapter-contract.md. Только читает
 * источник, в БД ничего не пишет. Код возврата 1 — есть нарушения.
 */
class CheckAdapter extends Command
{
    use ResolvesMonthRange;

    protected $signature = 'adapter:check {--period= : Месяцы YYYY-MM:YYYY-MM; по умолчанию три последних}';

    protected $description = 'Проверить источник данных на контракт DataSourceAdapter (ничего не пишет)';

    public function handle(AdapterContractChecker $checker, DataSourceAdapterFactory $factory): int
    {
        try {
            $adapter = app(DataSourceAdapter::class);
            $range = $this->monthRange($this->option('period'), $adapter, 3);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Проверка контракта: источник=%s, период=%s..%s',
            $factory->source(),
            $range->start->format('Y-m-d'),
            $range->end->format('Y-m-d'),
        ));

        $report = $checker->check($adapter, $range);

        $this->line(implode(', ', array_map(
            static fn (string $k, int $v) => "{$k}: {$v}",
            array_keys($report->counts),
            $report->counts,
        )));
        foreach ($report->skipped as $skipped) {
            $this->line("пропущено — {$skipped}");
        }

        if ($report->passed()) {
            $this->info('Нарушений нет.');

            return self::SUCCESS;
        }

        foreach ($report->violations as $v) {
            $this->error("[{$v->rule}] {$v->message} (случаев: {$v->count})");
            foreach ($v->examples as $example) {
                $this->line("    {$example}");
            }
        }
        $this->error(sprintf('Нарушено правил: %d.', count($report->violations)));

        return self::FAILURE;
    }
}
