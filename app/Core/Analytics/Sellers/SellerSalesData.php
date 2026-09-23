<?php

namespace App\Core\Analytics\Sellers;

use App\Core\Domain\Deal;

/**
 * Продажи, свёрнутые по (месяц, продавец): количество и сумма, плюс первый
 * и последний день продаж каждого продавца во всём наборе (для нормировки
 * новичков и уволенных). Сделки без продавца собираются под NO_SELLER, а не
 * теряются — сумма по ключам месяца всегда равна итогу по сделкам месяца.
 */
final class SellerSalesData
{
    /** Зарезервированный entity_id для продаж без продавца. */
    public const string NO_SELLER = '__none__';

    /** @var array<string, array<string, array{count: int, amount: float}>> */
    private array $byMonth = [];

    /** @var array<string, array{string, string}> */
    private array $activity = [];

    /**
     * @param  iterable<Deal>  $deals
     */
    public static function fromDeals(iterable $deals): self
    {
        $data = new self;
        foreach ($deals as $deal) {
            $key = $deal->sellerId ?? self::NO_SELLER;
            $month = $deal->date->format('Y-m');
            $day = $deal->date->format('Y-m-d');

            $cell = $data->byMonth[$month][$key] ?? ['count' => 0, 'amount' => 0.0];
            // Нетто: в Deal нет признака возврата/типа документа, поэтому сумма
            // сделки берётся как есть (возвраты, если источник отдаёт их
            // отрицательными сделками, вычитаются сами).
            $data->byMonth[$month][$key] = ['count' => $cell['count'] + 1, 'amount' => $cell['amount'] + $deal->amount];

            [$first, $last] = $data->activity[$key] ?? [$day, $day];
            $data->activity[$key] = [min($first, $day), max($last, $day)];
        }
        ksort($data->byMonth);

        return $data;
    }

    /** @return list<string> месяцы 'Y-m' по возрастанию */
    public function months(): array
    {
        return array_keys($this->byMonth);
    }

    /** Ключи месяца; NO_SELLER присутствует всегда (нулевой, если таких продаж нет). */
    public function keys(string $month): array
    {
        return array_values(array_unique([...array_keys($this->byMonth[$month] ?? []), self::NO_SELLER]));
    }

    public function count(string $month, string $key): int
    {
        return $this->byMonth[$month][$key]['count'] ?? 0;
    }

    public function amount(string $month, string $key): float
    {
        return $this->byMonth[$month][$key]['amount'] ?? 0.0;
    }

    public function totalCount(string $month): int
    {
        return array_sum(array_column($this->byMonth[$month] ?? [], 'count'));
    }

    /** @return array{string, string}|null первый и последний день продаж ('Y-m-d') */
    public function activity(string $key): ?array
    {
        return $this->activity[$key] ?? null;
    }
}
