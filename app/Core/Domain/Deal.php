<?php

namespace App\Core\Domain;

use DateTimeImmutable;

final readonly class Deal
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $productId,
        public float $amount,
        public DateTimeImmutable $date,
        public array $meta = [],
        public ?string $sellerId = null,
    ) {}
}
