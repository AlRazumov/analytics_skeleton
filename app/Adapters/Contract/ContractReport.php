<?php

namespace App\Adapters\Contract;

/**
 * Результат проверки адаптера: нарушения, объёмы данных в проверенном
 * диапазоне (чтобы «0 нарушений на 0 сделок» было видно) и правила,
 * которые не проверялись (например, остатки без capability).
 */
final readonly class ContractReport
{
    /**
     * @param  list<ContractViolation>  $violations
     * @param  array<string, int>  $counts  deals, products, warehouses, sellers, movements, balances
     * @param  list<string>  $skipped  коды пропущенных правил с причиной
     */
    public function __construct(
        public array $violations,
        public array $counts,
        public array $skipped = [],
    ) {}

    public function passed(): bool
    {
        return $this->violations === [];
    }

    /** @return list<string> */
    public function rules(): array
    {
        return array_values(array_unique(array_map(static fn (ContractViolation $v) => $v->rule, $this->violations)));
    }
}
