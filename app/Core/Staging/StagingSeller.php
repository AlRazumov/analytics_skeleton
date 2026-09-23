<?php

namespace App\Core\Staging;

use Illuminate\Database\Eloquent\Model;

class StagingSeller extends Model
{
    protected $table = 'staging_sellers';

    protected $fillable = [
        'external_id',
        'name',
        'branch_external_id',
        'is_active',
        'meta',
        'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
        'synced_at' => 'datetime',
    ];
}
