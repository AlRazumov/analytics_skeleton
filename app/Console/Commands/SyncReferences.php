<?php

namespace App\Console\Commands;

use App\Core\Contracts\DataSourceAdapter;
use App\Sync\ReferenceSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Загружает справочники товаров и складов из источника (analytics.source) в БД. */
class SyncReferences extends Command
{
    protected $signature = 'reference:sync';

    protected $description = 'Синхронизировать справочники товаров и складов из источника данных в БД';

    public function handle(ReferenceSyncService $sync): int
    {
        try {
            $counts = $sync->sync(app(DataSourceAdapter::class));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Справочники синхронизированы: товаров — {$counts['products']}, складов — {$counts['warehouses']}.");

        return self::SUCCESS;
    }
}
