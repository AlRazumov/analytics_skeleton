<?php

use App\Adapters\Mock\MockDataProfile;
use App\Adapters\MockAdapter;
use App\Core\Domain\DateRange;
use App\Core\Domain\Deal;
use App\Core\Domain\Enums\StockMovementType;
use App\Core\Domain\StockMovement;

/**
 * Сделки и движения мока за всю историю: продажа ↔ сделка, возврат ↔
 * сделка-возврат, одна цена единицы на товар.
 *
 * @return array{list<Deal>, list<StockMovement>}
 */
function mockHistory(MockAdapter $adapter): array
{
    $range = new DateRange($adapter->historyStart(), $adapter->historyEnd());

    return [
        iterator_to_array($adapter->fetchDeals($range), false),
        iterator_to_array($adapter->fetchStockMovements($range), false),
    ];
}

$matchesMovements = function (MockDataProfile $profile) {
    $adapter = new MockAdapter($profile, 1);
    [$deals, $movements] = mockHistory($adapter);

    // Продажа и возврат товара в день — ключ (товар, день продажи, склад).
    $sales = [];
    $returns = [];
    foreach ($movements as $m) {
        $day = $m->date->format('Y-m-d');
        if ($m->type === StockMovementType::Sale) {
            $sales["{$m->productId}|{$day}|{$m->warehouseId}"] = $m;
        } elseif (isset($m->meta['return_of_day'])) {
            $saleDay = $adapter->historyStart()->modify("+{$m->meta['return_of_day']} days")->format('Y-m-d');
            $returns["{$m->productId}|{$saleDay}|{$m->warehouseId}"] = $m;
        }
    }

    $saleDeals = [];
    $refundDeals = [];
    $pricePerUnit = [];
    foreach ($deals as $deal) {
        if (str_starts_with($deal->id, 'deal-return-')) {
            $refundDeals[] = $deal;

            continue;
        }
        $key = "{$deal->productId}|{$deal->date->format('Y-m-d')}|".substr($deal->id, strrpos($deal->id, '-wh-') + 1);
        expect($sales)->toHaveKey($key);
        $sale = $sales[$key];
        expect($deal->date)->toEqual($sale->date);
        $saleDeals[$key] = $deal;
        $pricePerUnit[$deal->productId][] = $deal->amount / -$sale->quantity;
    }

    // Каждой продаже — ровно одна сделка; цена единицы у товара одна (до копеек округления).
    expect(count($saleDeals))->toBe(count($sales));
    foreach ($pricePerUnit as $productId => $prices) {
        expect(max($prices) - min($prices))->toBeLessThan(0.01, $productId);
    }

    // Возврат — сделка с минусом суммы своей продажи, тем же продавцом, в день прихода-возврата.
    expect(count($refundDeals))->toBe(count($returns));
    foreach ($refundDeals as $refund) {
        $key = substr($refund->id, strlen('deal-return-'));
        [$index, $saleDay, $warehouse] = explode('-', $key, 3);
        $saleKey = "prod-{$index}|".$adapter->historyStart()->modify("+{$saleDay} days")->format('Y-m-d')."|{$warehouse}";
        expect($saleDeals)->toHaveKey($saleKey)
            ->and($refund->amount)->toBe(-$saleDeals[$saleKey]->amount)
            ->and($refund->sellerId)->toBe($saleDeals[$saleKey]->sellerId)
            ->and($refund->date)->toEqual($returns[$saleKey]->date)
            ->and($returns[$saleKey]->quantity)->toBe(-$sales[$saleKey]->quantity);
    }
};

it('turns every sale into one deal at one unit price and every return into a refund (Small)', fn () => $matchesMovements(MockDataProfile::Small));

it('turns every sale into one deal at one unit price and every return into a refund (Medium)', fn () => $matchesMovements(MockDataProfile::Medium))->group('slow');

it('stops the deals of dead products at their last sale and of lost-sales products before the last month', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $manifest = $adapter->manifest();
    [$deals, $movements] = mockHistory($adapter);
    $lastDeal = [];
    foreach ($deals as $deal) {
        $lastDeal[$deal->productId] = max($lastDeal[$deal->productId] ?? '', $deal->date->format('Y-m-d'));
    }

    foreach ($manifest->deadProducts as $id => $lastSale) {
        expect($lastDeal[$id])->toBe($lastSale);
    }

    $lastMonth = $adapter->historyEnd()->format('Y-m');
    $stock = [];
    foreach ($movements as $m) {
        $stock[$m->productId] = ($stock[$m->productId] ?? 0) + $m->quantity;
        if (in_array($m->productId, $manifest->lostSalesProductIds, true) && $m->type === StockMovementType::Sale) {
            expect($m->date->format('Y-m'))->not->toBe($lastMonth);
        }
    }
    foreach ($manifest->lostSalesProductIds as $id) {
        expect($lastDeal[$id] < $lastMonth.'-01')->toBeTrue()
            ->and($stock[$id])->toBeGreaterThan(0.0);
    }
});

it('keeps the new-year peak in the revenue of ordinary products', function () {
    $adapter = new MockAdapter(MockDataProfile::Small, 1);
    $m = $adapter->manifest();
    $scenario = array_merge(array_keys($m->deadProducts), array_keys($m->nearZeroProducts), array_keys($m->gapProducts),
        array_keys($m->spikeProducts), $m->seasonalProductIds, array_keys($m->imbalanceProducts), $m->lostSalesProductIds,
        array_keys($m->noSalesDonorProducts), array_keys($m->returnProducts));
    [$deals] = mockHistory($adapter);

    $revenue = [];
    foreach ($deals as $deal) {
        if (! in_array($deal->productId, $scenario, true)) {
            $month = $deal->date->format('m');
            $revenue[$month] = ($revenue[$month] ?? 0.0) + $deal->amount;
        }
    }

    // Декабрь (множитель 1,8) заметно выше соседних обычных месяцев.
    expect($revenue['12'])->toBeGreaterThan(1.3 * $revenue['10'])
        ->and($revenue['12'])->toBeGreaterThan(1.3 * $revenue['03']);
});
