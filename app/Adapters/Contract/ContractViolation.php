<?php

namespace App\Adapters\Contract;

/**
 * Нарушение правила контракта DataSourceAdapter: код правила (см.
 * docs/adapter-contract.md), что не так, сколько всего случаев и до
 * AdapterContractChecker::MAX_EXAMPLES примеров.
 */
final readonly class ContractViolation
{
    /**
     * @param  list<string>  $examples
     */
    public function __construct(
        public string $rule,
        public string $message,
        public int $count = 1,
        public array $examples = [],
    ) {}
}
