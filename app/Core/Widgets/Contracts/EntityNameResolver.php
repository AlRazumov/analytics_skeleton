<?php

namespace App\Core\Widgets\Contracts;

/**
 * Названия сущностей произвольного типа (product, seller, ...) для
 * обобщённого виджета топ-N. Один вызов на набор id; id без названия в
 * результат не попадают.
 */
interface EntityNameResolver
{
    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => название
     */
    public function names(string $entityType, array $ids): array;
}
