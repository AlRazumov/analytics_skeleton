<?php

namespace App\Sync;

use App\Core\Contracts\DataSourceAdapter;
use App\Core\Staging\StagingProduct;
use App\Core\Staging\StagingSeller;
use App\Core\Staging\StagingWarehouse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Синхронизирует справочники товаров, складов и продавцов из адаптера в staging_*:
 * поток fetch* → upsert чанками по external_id (name/category/meta
 * обновляются). Записи, которых больше нет в источнике, НЕ удаляются.
 */
final class ReferenceSyncService
{
    public const int CHUNK_SIZE = 1000;

    /**
     * @return array{products: int, warehouses: int, sellers: int} число обработанных записей источника
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

        $sellers = $this->upsert(
            StagingSeller::class,
            $adapter->fetchSellers(),
            fn ($s) => ['external_id' => $s->id, 'name' => $s->name, 'branch_external_id' => $s->branchId, 'is_active' => $s->isActive, 'meta' => null],
            ['name', 'branch_external_id', 'is_active', 'meta'],
            $now,
        );

        return ['products' => $products, 'warehouses' => $warehouses, 'sellers' => $sellers];
    }

    /**
     * @template T of object
     *
     * @param  class-string<Model>  $model
     * @param  iterable<T>  $items
     * @param  callable(T): array<string, mixed>  $toRow  строка с ключом external_id
     * @param  list<string>  $updateColumns
     */
    private function upsert(string $model, iterable $items, callable $toRow, array $updateColumns, CarbonInterface $now): int
    {
        $count = 0;
        $chunk = [];

        foreach ($items as $item) {
            $row = $toRow($item) + ['synced_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            // Дубль id внутри чанка ON CONFLICT не переживёт — побеждает последний.
            $chunk[(string) $row['external_id']] = $row;
            $count++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->flush($model, $chunk, $updateColumns);
                $chunk = [];
            }
        }
        $this->flush($model, $chunk, $updateColumns);

        return $count;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<array-key, array<string, mixed>>  $chunk
     * @param  list<string>  $updateColumns
     */
    private function flush(string $model, array $chunk, array $updateColumns): void
    {
        if ($chunk !== []) {
            $model::query()->upsert(array_values($chunk), ['external_id'], [...$updateColumns, 'synced_at', 'updated_at']);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function json(array $meta): ?string
    {
        return $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
