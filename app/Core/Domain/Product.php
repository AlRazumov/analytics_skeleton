<?php

namespace App\Core\Domain;

final readonly class Product
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $category = null,
        public array $meta = [],
    ) {
    }
}
