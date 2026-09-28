<?php

namespace App\Support;

use Illuminate\Support\Env;
use InvalidArgumentException;

/**
 * Числовые настройки из .env для config/analytics.php. Переменная не задана
 * или пустая — значение по умолчанию; иначе — строгий разбор: не число или
 * число вне [$min; $max] — InvalidArgumentException при загрузке конфига (опечатка
 * в .env видна сразу, а не превращается молча в 0). Списки — через запятую.
 */
final class EnvNumber
{
    public static function int(string $key, int $default, int $min = 0, ?int $max = null): int
    {
        $raw = self::raw($key);

        return $raw === null ? $default : self::parseInt($key, $raw, $min, $max);
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    public static function intList(string $key, array $default, int $min = 0): array
    {
        $raw = self::raw($key);

        return $raw === null ? $default : array_map(fn (string $item) => self::parseInt($key, $item, $min), self::items($raw));
    }

    /**
     * @param  list<float>  $default
     * @return list<float>
     */
    public static function floatList(string $key, array $default, float $min = 0.0): array
    {
        $raw = self::raw($key);

        return $raw === null ? $default : array_map(fn (string $item) => self::parseFloat($key, $item, $min), self::items($raw));
    }

    private static function raw(string $key): ?string
    {
        $value = Env::get($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            throw new InvalidArgumentException("{$key}: ожидается число, получено ".var_export($value, true).'.');
        }

        return trim((string) $value);
    }

    /** @return list<string> */
    private static function items(string $raw): array
    {
        return array_map('trim', explode(',', $raw));
    }

    private static function parseInt(string $key, string $raw, int $min, ?int $max = null): int
    {
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            throw new InvalidArgumentException("{$key}: ожидается целое число, получено '{$raw}'.");
        }
        $value = (int) $raw;
        if ($value < $min) {
            throw new InvalidArgumentException("{$key}: значение должно быть не меньше {$min}, получено {$value}.");
        }
        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("{$key}: значение должно быть не больше {$max}, получено {$value}.");
        }

        return $value;
    }

    private static function parseFloat(string $key, string $raw, float $min): float
    {
        if (! is_numeric($raw)) {
            throw new InvalidArgumentException("{$key}: ожидается число, получено '{$raw}'.");
        }
        $value = (float) $raw;
        if ($value < $min) {
            throw new InvalidArgumentException("{$key}: значение должно быть не меньше {$min}, получено {$value}.");
        }

        return $value;
    }
}
