<?php

namespace App\Http\Controllers\Dashboards;

use App\Core\Widgets\CategoryProvider;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Ответ CSV-выгрузки: `{$name}-{месяц}[-{суффиксы}].csv`. Период null —
 * расчёт метрики ещё не выполнялся, выгружать нечего (404).
 */
trait RespondsWithCsv
{
    /** @param list<?string> $suffixes null пропускается */
    private function csvResponse(string $csv, string $name, ?string $periodKey, array $suffixes = []): Response
    {
        if ($periodKey === null) {
            abort(404);
        }

        $month = substr($periodKey, strpos($periodKey, ':') + 1);
        $filename = implode('-', array_filter([$name, $month, ...$suffixes], static fn (?string $part) => $part !== null && $part !== ''));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }

    /** Суффикс имени файла для `?category=`: транслит подписи (ASCII — без filename*). */
    private function categorySuffix(?string $category): ?string
    {
        return $category === null ? null : Str::slug(CategoryProvider::label($category));
    }
}
