<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Создаёт пользователя standalone-части. Пароль запрашивается
 * интерактивно (secret + подтверждение) и НЕ принимается ни
 * аргументом, ни опцией — иначе он попадал бы в историю shell.
 * Существующего пользователя команда не изменяет и не перезаписывает.
 * Email хранится в нижнем регистре (Fortify приводит логин к нижнему
 * регистру при входе — см. lowercase_usernames).
 */
class CreateUser extends Command
{
    protected $signature = 'users:create {email : Email нового пользователя} {--name= : Имя (по умолчанию — часть email до @)}';

    protected $description = 'Создать пользователя для входа в standalone-часть (пароль запрашивается интерактивно)';

    private const int MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name')) ?: (strstr($email, '@', true) ?: $email);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
                'name' => ['required', 'string', 'max:255'],
            ],
        );
        if ($validator->fails()) {
            return $this->failWith($validator->errors()->all());
        }

        $password = (string) $this->secret('Пароль (минимум '.self::MIN_PASSWORD_LENGTH.' символов)');
        $confirmation = (string) $this->secret('Повторите пароль');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'confirmed']],
        );
        if ($validator->fails()) {
            return $this->failWith($validator->errors()->all());
        }

        try {
            User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        } catch (UniqueConstraintViolationException) {
            return $this->failWith(['Пользователь с таким email уже существует.']);
        }

        $this->info("Пользователь {$email} создан.");

        return self::SUCCESS;
    }

    /** @param  string[]  $messages */
    private function failWith(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
