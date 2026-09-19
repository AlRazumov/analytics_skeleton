<?php

namespace App\Adapters;

use App\Adapters\Mock\MockDataProfile;
use App\Core\Contracts\DataSourceAdapter;
use InvalidArgumentException;

/**
 * Единственное место в app/, где создаётся конкретный адаптер: выбор по
 * analytics.source. Неизвестное значение — исключение с перечнем допустимых.
 */
final class DataSourceAdapterFactory
{
    public const array SOURCES = ['mock'];

    /**
     * @param  MockDataProfile|null  $mockProfile  переопределяет analytics.mock.profile (только для source=mock)
     */
    public function make(?MockDataProfile $mockProfile = null): DataSourceAdapter
    {
        $source = (string) config('analytics.source');

        return match ($source) {
            'mock' => new MockAdapter(
                $mockProfile ?? $this->configuredProfile(),
                (int) config('analytics.mock.seed'),
            ),
            default => throw new InvalidArgumentException(
                "Неизвестный источник данных analytics.source='{$source}'. Допустимые значения: ".implode(', ', self::SOURCES).'.',
            ),
        };
    }

    public function source(): string
    {
        return (string) config('analytics.source');
    }

    private function configuredProfile(): MockDataProfile
    {
        $value = (string) config('analytics.mock.profile');

        return MockDataProfile::tryFrom($value) ?? throw new InvalidArgumentException(
            "Неверный analytics.mock.profile='{$value}'. Допустимые значения: ".implode(', ', array_map(
                static fn (MockDataProfile $p) => $p->value, MockDataProfile::cases(),
            )).'.',
        );
    }
}
