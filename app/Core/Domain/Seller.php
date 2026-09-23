<?php

namespace App\Core\Domain;

/**
 * Продавец из справочника источника. $branchId — id склада/подразделения
 * в терминах того же источника (как Deal::$productId), null — не известно.
 */
final readonly class Seller
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $branchId = null,
        public bool $isActive = true,
    ) {}
}
