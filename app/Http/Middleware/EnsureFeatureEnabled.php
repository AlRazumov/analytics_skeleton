<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<name>[,<name>...]` — 404, если выключены ВСЕ перечисленные
 * флаги analytics.features.* (достаточно одного включённого). Флаги
 * влияют только на показ; расчёт метрик от них не зависит.
 */
final class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            if (config("analytics.features.{$feature}") === true) {
                return $next($request);
            }
        }

        abort(404);
    }
}
