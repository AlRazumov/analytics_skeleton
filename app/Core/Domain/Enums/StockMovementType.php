<?php

namespace App\Core\Domain\Enums;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Sale = 'sale';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Writeoff = 'writeoff';
    case Adjustment = 'adjustment';
}
