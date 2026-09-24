<?php

namespace App\Console\Commands;

use App\Adapters\DataSourceAdapterFactory;
use App\Adapters\MockAdapter;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Готовит демо на данных MockAdapter: справочники + расчёт метрик за ВСЮ
 * историю мока одним прогоном (для Medium это 24 месяца, чтобы работало
 * сравнение «год к году»; для Small — то же, что период по умолчанию).
 * Миграции не запускает — только проверяет, что таблицы есть. Пользователя
 * создаёт лишь при --email (пароль — интерактивно, через users:create);
 * паролей по умолчанию нет.
 */
class InstallDemo extends Command
{
    protected $signature = 'demo:install {--email= : Создать пользователя с этим email (пароль запросят интерактивно)}';

    protected $description = 'Подготовить демо на данных мока: справочники, расчёт метрик, при --email — пользователь';

    /** @var list<string> */
    private const array REQUIRED_TABLES = ['users', 'metrics_snapshots', 'staging_products', 'staging_warehouses', 'staging_sellers'];

    public function handle(DataSourceAdapterFactory $factory): int
    {
        if ($factory->source() !== 'mock') {
            $this->error("demo:install работает только при analytics.source=mock (сейчас '{$factory->source()}').");

            return self::FAILURE;
        }

        $missing = array_values(array_filter(self::REQUIRED_TABLES, static fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->error('Нет таблиц: '.implode(', ', $missing).'. Сначала выполните `php artisan migrate`.');

            return self::FAILURE;
        }

        try {
            $adapter = $factory->make();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        // Окно демо берётся из истории мока (historyStart/historyEnd есть только у него).
        if (! $adapter instanceof MockAdapter) {
            $this->error('demo:install ожидает MockAdapter, фабрика вернула '.$adapter::class.'.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('email')));
        if ($this->option('email') !== null && $email === '') {
            $this->error('--email не может быть пустым.');

            return self::FAILURE;
        }

        $period = $adapter->historyStart()->format('Y-m').':'.$adapter->historyEnd()->format('Y-m');

        if ($this->call('reference:sync') !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('metrics:calculate', ['--period' => $period]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($email !== '') {
            if (User::query()->where('email', $email)->exists()) {
                $this->info("Пользователь {$email} уже существует — не изменяю.");
            } elseif ($this->call('users:create', ['email' => $email]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->info("Демо готово (данные синтетические, период {$period}). Откройте /dashboards/overview.");

        return self::SUCCESS;
    }
}
