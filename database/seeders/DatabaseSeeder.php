<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Пользователей сидер не создаёт: фабричный пароль известен всем и
        // короче политики users:create. Пользователь — `users:create` или
        // `demo:install --email=...`; демо-данные — `demo:install`.
    }
}
