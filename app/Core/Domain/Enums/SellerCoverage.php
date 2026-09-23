<?php

namespace App\Core\Domain\Enums;

/**
 * Насколько сделки источника несут продавца: у всех (Full), только у
 * части (Partial) или ни у одной (None — блок продавцов не показывается).
 */
enum SellerCoverage: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';
}
