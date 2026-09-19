<?php

namespace App\Core\Staging;

use Illuminate\Database\Eloquent\Model;

class StagingWarehouse extends Model
{
    protected $table = 'staging_warehouses';

    protected $fillable = [
        'external_id',
        'name',
        'meta',
        'synced_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'synced_at' => 'datetime',
    ];
}
