<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not create users with the well-known factory password', function () {
    $this->seed();

    expect(User::query()->count())->toBe(0);
});
