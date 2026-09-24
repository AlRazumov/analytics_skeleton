<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Меняет пароль существующего пользователя standalone-части. Пароль
 * запрашивается интерактивно (secret + подтверждение) и НЕ принимается
 * ни аргументом, ни опцией — иначе он попадал бы в историю shell.
 * Хэшируется штатным cast'ом hashed модели User. Открытые сессии и
 * cookie «запомнить меня» после смены пароля перестают действовать.
 */
class ChangeUserPassword extends Command
{
    protected $signature = 'users:password {email : Email существующего пользователя}';

    protected $description = 'Сменить пароль пользователя standalone-части (пароль запрашивается интерактивно)';

    private const int MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("Пользователь {$email} не найден.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Новый пароль (минимум '.self::MIN_PASSWORD_LENGTH.' символов)');
        $confirmation = (string) $this->secret('Повторите пароль');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'confirmed']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Новый remember_token гасит cookie «запомнить меня»; уже открытые
        // сессии разлогинивает middleware auth.session (сверяет хэш пароля).
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $this->info("Пароль пользователя {$email} изменён.");

        return self::SUCCESS;
    }
}
