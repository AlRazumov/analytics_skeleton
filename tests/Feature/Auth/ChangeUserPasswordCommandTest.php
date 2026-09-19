<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('changes the password: the new one logs in, the old one does not', function () {
    User::factory()->create(['email' => 'anna@example.com', 'password' => 'old-password-123']);

    $this->artisan('users:password', ['email' => 'Anna@Example.com'])
        ->expectsQuestion('Новый пароль (минимум 12 символов)', 'brand-new-password')
        ->expectsQuestion('Повторите пароль', 'brand-new-password')
        ->expectsOutputToContain('изменён')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'anna@example.com')->firstOrFail();
    expect($user->password)->not->toBe('brand-new-password')
        ->and(Hash::check('brand-new-password', $user->password))->toBeTrue();

    $this->post('/login', ['email' => 'anna@example.com', 'password' => 'old-password-123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => 'anna@example.com', 'password' => 'brand-new-password']);
    $this->assertAuthenticated();
});

it('fails for a missing user without asking for a password', function () {
    $this->artisan('users:password', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('не найден')
        ->assertExitCode(1);
});

it('rejects a too short password and keeps the old one', function () {
    $user = User::factory()->create(['email' => 'anna@example.com', 'password' => 'old-password-123']);
    $hash = $user->password;

    $this->artisan('users:password', ['email' => 'anna@example.com'])
        ->expectsQuestion('Новый пароль (минимум 12 символов)', 'short')
        ->expectsQuestion('Повторите пароль', 'short')
        ->assertExitCode(1);

    expect($user->fresh()->password)->toBe($hash);
});

it('rejects a mismatching confirmation', function () {
    User::factory()->create(['email' => 'anna@example.com']);

    $this->artisan('users:password', ['email' => 'anna@example.com'])
        ->expectsQuestion('Новый пароль (минимум 12 символов)', 'long-enough-password')
        ->expectsQuestion('Повторите пароль', 'another-long-password')
        ->assertExitCode(1);
});

it('does not accept the password as an argument or option', function () {
    $definition = app(Kernel::class)->all()['users:password']->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and(array_keys($definition->getOptions()))->not->toContain('password');
});
