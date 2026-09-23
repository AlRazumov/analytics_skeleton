<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Enums\StockMovementType;

it('gives the donor warehouse stock but never a single sale, and a real deficit on the paired warehouse', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();

    expect($manifest->noSalesDonorProducts)->not->toBeEmpty();

    $stock = [];
    foreach ($adapter->fetchStock() as $b) {
        $stock[$b->productId][$b->warehouseId] = $b->quantity;
    }

    $movements = [];
    foreach ($adapter->fetchStockMovements(new DateRange($adapter->historyStart(), $adapter->historyEnd())) as $m) {
        if (isset($manifest->noSalesDonorProducts[$m->productId])) {
            $movements[$m->productId][] = $m;
        }
    }

    foreach ($manifest->noSalesDonorProducts as $id => $fact) {
        $donor = $fact['donor_warehouse_id'];
        $deficit = $fact['deficit_warehouse_id'];
        expect($donor)->not->toBe($deficit);

        // Остаток на конец истории соответствует манифесту.
        expect($stock[$id][$donor])->toBe((float) $fact['donor_stock_at_end'])
            ->and($stock[$id][$deficit])->toBe((float) $fact['deficit_stock_at_end']);

        // Ни одной продажи на складе-доноре за всю историю; на дефицитном — только продажи и один приход.
        $donorSales = array_filter($movements[$id], fn ($m) => $m->warehouseId === $donor && $m->type === StockMovementType::Sale);
        expect($donorSales)->toBe([]);

        foreach ($movements[$id] as $m) {
            expect($m->type)->toBeIn([StockMovementType::Receipt, StockMovementType::Sale])
                ->and($m->warehouseId)->toBeIn([$donor, $deficit]);
        }

        // Покрытие дефицитного склада на конец истории — то, что заявлено манифестом (и оно маленькое).
        expect($fact['deficit_stock_at_end'] / $fact['deficit_daily_rate'])->toBe($fact['deficit_days_of_stock']);
    }
});
