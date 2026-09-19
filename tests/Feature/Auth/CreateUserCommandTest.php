<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a user, hashes the password and lets them log in', function () {
    $this->artisan('users:create', ['email' => 'New.User@Example.com', '--name' => 'Иван'])
        ->expectsQuestion('Пароль (минимум 12 символов)', 'long-enough-password')
        ->expectsQuestion('Повторите пароль', 'long-enough-password')
        ->expectsOutputToContain('создан')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();

    expect($user->name)->toBe('Иван')
        ->and($user->password)->not->toBe('long-enough-password')
        ->and(Hash::check('long-enough-password', $user->password))->toBeTrue();

    $this->post('/login', ['email' => 'new.user@example.com', 'password' => 'long-enough-password']);
    $this->assertAuthenticated();
});

it('defaults the name to the part of the email before @', function () {
    $this->artisan('users:create', ['email' => 'anna@example.com'])
        ->expectsQuestion('Пароль (минимум 12 символов)', 'long-enough-password')
        ->expectsQuestion('Повторите пароль', 'long-enough-password')
        ->assertExitCode(0);

    expect(User::query()->where('email', 'anna@example.com')->value('name'))->toBe('anna');
});

it('refuses a duplicate email without asking for a password and does not touch the existing user', function () {
    $existing = User::factory()->create(['email' => 'dup@example.com', 'name' => 'Original']);
    $hash = $existing->password;

    $this->artisan('users:create', ['email' => 'DUP@example.com', '--name' => 'Overwritten'])
        ->expectsOutputToContain('taken')
        ->assertExitCode(1);

    $existing->refresh();
    expect(User::query()->count())->toBe(1)
        ->and($existing->name)->toBe('Original')
        ->and($existing->password)->toBe($hash);
});

it('refuses a password shorter than 12 characters', function () {
    $this->artisan('users:create', ['email' => 'short@example.com'])
        ->expectsQuestion('Пароль (минимум 12 символов)', 'short-pass')
        ->expectsQuestion('Повторите пароль', 'short-pass')
        ->expectsOutputToContain('12')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

it('refuses a password that is not confirmed correctly', function () {
    $this->artisan('users:create', ['email' => 'mismatch@example.com'])
        ->expectsQuestion('Пароль (минимум 12 символов)', 'long-enough-password')
        ->expectsQuestion('Повторите пароль', 'another-long-password')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

it('refuses an invalid email', function (string $email) {
    $this->artisan('users:create', ['email' => $email])->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
})->with(['not-an-email', 'a@', '@example.com', 'two@@example.com']);

it('does not accept the password as an argument or option', function () {
    $command = app(Kernel::class)->all()['users:create'];
    $definition = $command->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and(array_keys($definition->getOptions()))->toContain('name')
        ->and(array_filter(array_keys($definition->getOptions()), fn ($o) => str_contains($o, 'pass')))->toBe([]);
});
