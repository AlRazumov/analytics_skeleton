<?php

namespace App\Core\Domain\Enums;

/** Desc — топ (наибольшие первыми), Asc — анти-топ (наименьшие первыми). */
enum Direction: string
{
    case Desc = 'desc';
    case Asc = 'asc';
}
