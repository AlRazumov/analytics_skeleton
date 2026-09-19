<?php

namespace App\Core\Analytics;

use InvalidArgumentException;

/**
 * Единственное место, где определён формат entity_id для
 * entity_type='product_warehouse': "<productId>:<warehouseId>".
 * Идентификаторы с ':' не допускаются (разбор был бы неоднозначным).
 */
final class ProductWarehouseKey
{
    public const ENTITY_TYPE = 'product_warehouse';

    public static function make(string $productId, string $warehouseId): string
    {
        if (str_contains($productId, ':') || str_contains($warehouseId, ':')) {
            throw new InvalidArgumentException("productId/warehouseId не должны содержать ':' ('{$productId}', '{$warehouseId}').");
        }

        return $productId.':'.$warehouseId;
    }

    /** @return array{string, string} [productId, warehouseId] */
    public static function parse(string $key): array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Неверный ключ product_warehouse '{$key}'.");
        }

        return [$parts[0], $parts[1]];
    }
}
