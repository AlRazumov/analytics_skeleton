<?php

namespace App\Core\Domain;

final readonly class Warehouse
{
    public function __construct(
        public string $id,
        public string $name,
        public array $meta = [],
    ) {}
}
