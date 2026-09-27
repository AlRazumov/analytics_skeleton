<?php

namespace App\Http\Controllers\Dashboards;

use Illuminate\Http\Response;

/**
 * Ответ CSV-выгрузки: `{$name}-{месяц}[-{$suffix}].csv`. Период null —
 * расчёт метрики ещё не выполнялся, выгружать нечего (404).
 */
trait RespondsWithCsv
{
    private function csvResponse(string $csv, string $name, ?string $periodKey, ?string $suffix = null): Response
    {
        if ($periodKey === null) {
            abort(404);
        }

        $month = substr($periodKey, strpos($periodKey, ':') + 1);
        $filename = implode('-', array_filter([$name, $month, $suffix], static fn (?string $part) => $part !== null));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }
}
