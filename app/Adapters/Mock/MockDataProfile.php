<?php

namespace App\Adapters\Mock;

/**
 * Условные профили масштаба генерируемых мок-данных.
 *
 * Все числовые значения в этом enum — ОРИЕНТИРОВОЧНЫЕ. Точных объёмов
 * данных от клиента по 1С-направлению пока нет (клиент только
 * согласился на работу, конкретные цифры не переданы). Это три
 * условных порядка величины, нужные для проверки потоковой генерации
 * и производительности на разном масштабе — подлежат калибровке, как
 * только появятся реальные цифры.
 */
enum MockDataProfile: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    /** Ориентировочно ~50/500/5000 товаров. См. докблок класса. */
    public function productCount(): int
    {
        return match ($this) {
            self::Small => 50,
            self::Medium => 500,
            self::Large => 5000,
        };
    }

    /** Ориентировочно ~200/5000/50000 сделок в месяц. См. докблок класса. */
    public function dealsPerMonth(): int
    {
        return match ($this) {
            self::Small => 200,
            self::Medium => 5000,
            self::Large => 50000,
        };
    }

    /**
     * Количество складов растёт с профилем; минимум два, чтобы
     * перемещения между складами присутствовали в любом профиле.
     * Значение ориентировочное.
     */
    public function warehouseCount(): int
    {
        return match ($this) {
            self::Small => 2,
            self::Medium => 3,
            self::Large => 5,
        };
    }

    /**
     * @return list<string>
     */
    public function warehouseIds(): array
    {
        return array_map(
            static fn (int $i): string => "wh-{$i}",
            range(1, $this->warehouseCount()),
        );
    }

    /** Глубина истории движений в днях (до конца окна истории). Ориентировочно. */
    public function historyDays(): int
    {
        return match ($this) {
            self::Small => 365,
            self::Medium, self::Large => 730,
        };
    }

    public function scenarios(): MockScenarioConfig
    {
        return match ($this) {
            self::Small => new MockScenarioConfig(2, 2, 2, 2, 4, [100, 250]),
            self::Medium => new MockScenarioConfig(5, 5, 5, 5, 25, [100, 150, 250, 400]),
            self::Large => new MockScenarioConfig(10, 10, 10, 10, 100, [100, 150, 250, 400]),
        };
    }
}
