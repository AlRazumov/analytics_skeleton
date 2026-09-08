<?php

namespace App\Core\Staging;

use Illuminate\Database\Eloquent\Model;

class StagingProduct extends Model
{
    protected $table = 'staging_products';

    protected $fillable = [
        'external_id',
        'name',
        'category',
        'meta',
        'synced_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'synced_at' => 'datetime',
    ];
}
