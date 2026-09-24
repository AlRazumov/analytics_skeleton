<?php

namespace App\Core\Domain;

final readonly class Warehouse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $meta = [],
    ) {}
}
