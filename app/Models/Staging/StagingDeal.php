<?php

namespace App\Models\Staging;

use Illuminate\Database\Eloquent\Model;

class StagingDeal extends Model
{
    protected $table = 'staging_deals';

    protected $fillable = [
        'external_id',
        'product_external_id',
        'seller_external_id',
        'amount',
        'occurred_at',
        'meta',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'occurred_at' => 'datetime',
        'meta' => 'array',
        'synced_at' => 'datetime',
    ];
}
