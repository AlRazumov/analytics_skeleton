<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const STANDALONE_PATHS = ['/dashboards/overview', '/dashboards/abc-xyz', '/demo/widgets'];

function makeUser(string $email = 'user@example.com', string $password = 'correct-horse-battery'): User
{
    return User::factory()->create(['email' => $email, 'password' => $password]);
}

it('redirects guests from every standalone route to /login', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(STANDALONE_PATHS);

it('keeps the health check public (auth is not applied globally)', function () {
    // iframe-группы в проекте пока нет (IframeLayout отложен) — фиксируем, что
    // middleware auth не повешен глобально.
    $this->get('/up')->assertOk();
});

it('redirects / to the overview dashboard, and guests on to /login', function () {
    $this->get('/')->assertRedirect('/dashboards/overview');

    $this->followingRedirects()->get('/')->assertSee('Вход');
});

it('does not expose framework storage routes', function () {
    $this->get('/storage/anything.txt')->assertNotFound();
    $this->put('/storage/anything.txt')->assertNotFound();
});

it('shows the login page in the project layout without dashboard navigation', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Вход')
        ->assertSee('name="password"', false)
        ->assertDontSee('ABC/XYZ-анализ')
        ->assertDontSee('cdn.jsdelivr.net');
});

it('logs a user in and redirects to the main standalone page by default', function () {
    makeUser();

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/dashboards/overview');

    $this->assertAuthenticated();
    $this->get('/dashboards/overview')->assertOk()->assertSee('Выйти');
});

it('redirects to the intended page after login', function () {
    makeUser();

    $this->get('/dashboards/abc-xyz')->assertRedirect('/login');
    $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/dashboards/abc-xyz');
});

it('accepts the email in any letter case (Fortify lowercases the login)', function () {
    makeUser('user@example.com');

    $this->post('/login', ['email' => 'User@Example.com', 'password' => 'correct-horse-battery']);

    $this->assertAuthenticated();
});

it('does not authenticate with a wrong password and shows an error', function () {
    makeUser();

    $this->from('/login')->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->followingRedirects()->from('/login')->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
        ->assertSee('role="alert"', false);
});

it('throttles login attempts: the built-in Fortify limiter is on', function () {
    makeUser();
    RateLimiter::clear('user@example.com|127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        $this->from('/login')->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])->assertRedirect('/login');
    }

    // Шестая попытка — даже с ВЕРНЫМ паролем — блокируется лимитером (429 + Retry-After).
    $this->from('/login')->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse-battery'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $this->assertGuest();

    // Лимит привязан к паре email + IP: другой email не заблокирован.
    makeUser('other@example.com');
    $this->post('/login', ['email' => 'other@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/dashboards/overview');
});

it('logs out and closes the standalone pages again', function () {
    $this->actingAs(makeUser());
    $this->get('/dashboards/overview')->assertOk();

    $this->post('/logout')->assertRedirect();

    $this->assertGuest();
    $this->get('/dashboards/overview')->assertRedirect('/login');
});

it('does not expose registration, password reset or email verification routes', function (string $method, string $path) {
    $this->call($method, $path)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['GET', '/forgot-password'],
    ['POST', '/forgot-password'],
    ['GET', '/reset-password/some-token'],
    ['POST', '/reset-password'],
    ['GET', '/email/verify'],
    ['GET', '/email/verify/1/somehash'],
    ['POST', '/email/verification-notification'],
    ['GET', '/user/two-factor-qr-code'],
    ['PUT', '/user/profile-information'],
    ['PUT', '/user/password'],
]);

it('shows the failed-login message in Russian', function () {
    makeUser();

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong-password-123'])
        ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
});

it('has the throttle message in Russian', function () {
    // Реальный 429 при переборе отдаёт middleware throttle:login (текст фреймворка
    // «Too Many Attempts.», не переводится); auth.throttle используется Fortify
    // LockoutResponse — проверяем сам перевод.
    expect(app()->getLocale())->toBe('ru')
        ->and(trans('auth.throttle', ['seconds' => 42]))->toBe('Слишком много попыток входа. Повторите через 42 сек.');
});

it('shows the required-field message in Russian', function () {
    $this->post('/login', ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email' => 'Поле «Email» обязательно для заполнения.']);
});
