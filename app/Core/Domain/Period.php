<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\PeriodGranularity;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Один календарный период фиксированной гранулярности.
 *
 * Границы — ДАТЫ (время всегда 00:00), конец ВКЛЮЧИТЕЛЬНЫЙ: у
 * month:2026-08 start = 2026-08-01, end = 2026-08-31. Недели —
 * ISO-8601 (с понедельника, номер года — ISO-год: 2026-W01 может
 * начаться в декабре 2025). Конструктор проверяет, что start/end ровно
 * совпадают с границами календарного периода этой гранулярности.
 *
 * Канонические ключи: day:2026-08-19, week:2026-W34, month:2026-08,
 * quarter:2026-Q3, year:2026.
 */
final readonly class Period
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(
        public PeriodGranularity $granularity,
        DateTimeInterface $start,
        DateTimeInterface $end,
    ) {
        $this->start = self::toDate($start);
        $this->end = self::toDate($end);

        $expected = self::boundsFor($granularity, $this->start);
        if ($this->start != $expected[0] || $this->end != $expected[1]) {
            throw new InvalidArgumentException(sprintf(
                'Границы %s..%s не образуют календарный период "%s" (ожидалось %s..%s).',
                $this->start->format('Y-m-d'),
                $this->end->format('Y-m-d'),
                $granularity->value,
                $expected[0]->format('Y-m-d'),
                $expected[1]->format('Y-m-d'),
            ));
        }
    }

    /** Период заданной гранулярности, в который попадает дата. */
    public static function containing(PeriodGranularity $granularity, DateTimeInterface $date): self
    {
        [$start, $end] = self::boundsFor($granularity, self::toDate($date));

        return new self($granularity, $start, $end);
    }

    public static function fromKey(string $key): self
    {
        if (! preg_match('/^(day|week|month|quarter|year):(.+)$/', $key, $m)) {
            throw new InvalidArgumentException("Неверный ключ периода '{$key}'.");
        }

        $granularity = PeriodGranularity::from($m[1]);
        $value = $m[2];

        $date = match ($granularity) {
            PeriodGranularity::Day => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                ? self::parseDate($value) : null,
            PeriodGranularity::Week => preg_match('/^(\d{4})-W(\d{2})$/', $value, $p)
                ? self::isoWeekStart((int) $p[1], (int) $p[2]) : null,
            PeriodGranularity::Month => preg_match('/^\d{4}-\d{2}$/', $value)
                ? self::parseDate($value.'-01') : null,
            PeriodGranularity::Quarter => preg_match('/^(\d{4})-Q([1-4])$/', $value, $p)
                ? self::parseDate(sprintf('%04d-%02d-01', $p[1], ((int) $p[2] - 1) * 3 + 1)) : null,
            PeriodGranularity::Year => preg_match('/^\d{4}$/', $value)
                ? self::parseDate($value.'-01-01') : null,
        };

        if ($date === null) {
            throw new InvalidArgumentException("Неверный ключ периода '{$key}'.");
        }

        return self::containing($granularity, $date);
    }

    public function key(): string
    {
        return $this->granularity->value.':'.match ($this->granularity) {
            PeriodGranularity::Day => $this->start->format('Y-m-d'),
            PeriodGranularity::Week => $this->start->format('o-\WW'),
            PeriodGranularity::Month => $this->start->format('Y-m'),
            PeriodGranularity::Quarter => $this->start->format('Y').'-Q'.(intdiv((int) $this->start->format('n') - 1, 3) + 1),
            PeriodGranularity::Year => $this->start->format('Y'),
        };
    }

    /** Непосредственно предыдущий период той же гранулярности (для MoM/WoW/QoQ). */
    public function previous(): self
    {
        return self::containing($this->granularity, $this->start->modify('-1 day'));
    }

    /**
     * Тот же период годом ранее (для YoY). День: та же календарная дата
     * (29 февраля → 28 февраля); неделя: та же ISO-неделя прошлого
     * ISO-года (53-я → 52-я, если 53-й там нет); остальные — тот же
     * месяц/квартал/год.
     */
    public function yearAgo(): self
    {
        $year = (int) $this->start->format('Y');

        return match ($this->granularity) {
            PeriodGranularity::Day => self::containing($this->granularity, self::clampedDate(
                $year - 1, (int) $this->start->format('n'), (int) $this->start->format('j'),
            )),
            PeriodGranularity::Week => $this->isoWeekYearAgo(),
            default => self::containing($this->granularity, $this->start->modify('-1 year')),
        };
    }

    public function contains(DateTimeInterface $date): bool
    {
        $day = self::toDate($date);

        return $day >= $this->start && $day <= $this->end;
    }

    private function isoWeekYearAgo(): self
    {
        $isoYear = (int) $this->start->format('o') - 1;
        $week = (int) $this->start->format('W');

        // 28 декабря всегда в последней ISO-неделе года.
        $lastWeek = (int) (new DateTimeImmutable(sprintf('%d-12-28', $isoYear)))->format('W');

        return self::containing(
            PeriodGranularity::Week,
            self::isoWeekStart($isoYear, min($week, $lastWeek)),
        );
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private static function boundsFor(PeriodGranularity $granularity, DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        return match ($granularity) {
            PeriodGranularity::Day => [$date, $date],
            PeriodGranularity::Week => (function () use ($date) {
                $start = $date->modify('-'.((int) $date->format('N') - 1).' days');

                return [$start, $start->modify('+6 days')];
            })(),
            PeriodGranularity::Month => [
                self::parseDate(sprintf('%04d-%02d-01', $year, $month)),
                self::parseDate(sprintf('%04d-%02d-01', $year, $month))->modify('last day of this month'),
            ],
            PeriodGranularity::Quarter => (function () use ($year, $month) {
                $start = self::parseDate(sprintf('%04d-%02d-01', $year, intdiv($month - 1, 3) * 3 + 1));

                return [$start, $start->modify('+2 months')->modify('last day of this month')];
            })(),
            PeriodGranularity::Year => [
                self::parseDate(sprintf('%04d-01-01', $year)),
                self::parseDate(sprintf('%04d-12-31', $year)),
            ],
        };
    }

    private static function isoWeekStart(int $isoYear, int $week): ?DateTimeImmutable
    {
        if ($week < 1 || $week > 53) {
            return null;
        }

        $start = (new DateTimeImmutable('today'))->setISODate($isoYear, $week)->setTime(0, 0);

        // Несуществующая 53-я неделя молча перетекла бы в W01 следующего года.
        return (int) $start->format('o') === $isoYear ? $start : null;
    }

    private static function clampedDate(int $year, int $month, int $day): DateTimeImmutable
    {
        $last = (int) self::parseDate(sprintf('%04d-%02d-01', $year, $month))->format('t');

        return self::parseDate(sprintf('%04d-%02d-%02d', $year, $month, min($day, $last)));
    }

    private static function parseDate(string $ymd): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        // createFromFormat "нормализует" 2026-02-30 в 2026-03-02 — отсекаем.
        return $date !== false && $date->format('Y-m-d') === $ymd ? $date : null;
    }

    private static function toDate(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));
    }
}
