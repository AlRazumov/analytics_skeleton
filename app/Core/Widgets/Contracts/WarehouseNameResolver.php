<?php

namespace App\Core\Widgets\Contracts;

/**
 * Названия складов по id для подписей в таблицах. Один вызов на набор id;
 * id без названия в результат не попадают — вызывающий код показывает id.
 */
interface WarehouseNameResolver
{
    /**
     * @param  list<string>  $warehouseIds
     * @return array<string, string> warehouseId => название
     */
    public function names(array $warehouseIds): array;
}
