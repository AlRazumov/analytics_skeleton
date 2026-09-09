<?php

namespace App\Core\Staging;

use Illuminate\Database\Eloquent\Model;

class StagingStockMovement extends Model
{
    protected $table = 'staging_stock_movements';

    protected $fillable = [
        'external_id',
        'product_external_id',
        'warehouse_external_id',
        'to_warehouse_external_id',
        'quantity',
        'type',
        'occurred_at',
        'meta',
        'synced_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'occurred_at' => 'datetime',
        'meta' => 'array',
        'synced_at' => 'datetime',
    ];
}
