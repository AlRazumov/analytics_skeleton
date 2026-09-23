<?php

namespace App\Support;

/**
 * Единственный словарь человекочитаемых подписей для ключей метрик и
 * служебных колонок (слой приложения: core отдаёт виджетам сырые ключи).
 * Неизвестный ключ возвращается как есть — подпись для новой метрики
 * появляется здесь, а страница до этого не ломается.
 */
final class MetricLabels
{
    private const array LABELS = [
        'revenue' => 'Выручка',
        'turnover' => 'Оборачиваемость',
        'days_of_stock' => 'Дней до обнуления',
        'days_since_last_sale' => 'Дней без продаж',
        'abc_xyz_classification' => 'ABC/XYZ-класс',
        'sales_count' => 'Количество продаж',
        'sales_amount' => 'Сумма продаж',
        'avg_check' => 'Средний чек',
        'share_of_total' => 'Доля, %',
        'sales_per_active_day' => 'Продаж в активный день',
        'trend' => 'Изменение к пред. месяцу, %',
        'lost_sales' => 'Потерянные продажи (эвристика)',
        'stock_no_demand' => 'Остаток без продаж (эвристика)',
        'period' => 'Период',
        'entity_id' => 'Идентификатор',
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}
