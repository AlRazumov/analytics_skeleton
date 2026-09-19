<?php

namespace App\Sync;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingWarehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Синхронизирует справочники товаров и складов из адаптера в staging_*:
 * поток fetch* → upsert чанками по external_id (name/category/meta
 * обновляются). Записи, которых больше нет в источнике, НЕ удаляются.
 */
final class ReferenceSyncService
{
    public const int CHUNK_SIZE = 1000;

    /**
     * @return array{products: int, warehouses: int} число обработанных записей источника
     */
    public function sync(DataSourceAdapter $adapter): array
    {
        $now = now();

        $products = $this->upsert(
            StagingProduct::class,
            $adapter->fetchProducts(),
            fn ($p) => ['external_id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'meta' => $this->json($p->meta)],
            ['name', 'category', 'meta'],
            $now,
        );

        $warehouses = $this->upsert(
            StagingWarehouse::class,
            $adapter->fetchWarehouses(),
            fn ($w) => ['external_id' => $w->id, 'name' => $w->name, 'meta' => $this->json($w->meta)],
            ['name', 'meta'],
            $now,
        );

        return ['products' => $products, 'warehouses' => $warehouses];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $updateColumns
     */
    private function upsert(string $model, iterable $items, callable $toRow, array $updateColumns, $now): int
    {
        $count = 0;
        $chunk = [];

        $flush = function () use (&$chunk, $model, $updateColumns): void {
            if ($chunk !== []) {
                $model::query()->upsert(array_values($chunk), ['external_id'], [...$updateColumns, 'synced_at', 'updated_at']);
                $chunk = [];
            }
        };

        foreach ($items as $item) {
            $row = $toRow($item) + ['synced_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            // Дубль id внутри чанка ON CONFLICT не переживёт — побеждает последний.
            $chunk[$row['external_id']] = $row;
            $count++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $flush();
            }
        }
        $flush();

        return $count;
    }

    private function json(array $meta): ?string
    {
        return $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
    }
}
