<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds where a request spends its time: framework boot (slow when OPcache is off),
 * SQL, and the rest of the application. Adds a Server-Timing header (visible in the
 * browser's Network tab) and writes requests slower than chat.slow_request_ms to
 * storage/logs/performance-*.log.
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $queries = 0;
        $sqlMs = 0.0;
        $slowestSql = null;

        DB::listen(function (QueryExecuted $query) use (&$queries, &$sqlMs, &$slowestSql) {
            $queries++;
            $sqlMs += $query->time;
            if (! $slowestSql || $query->time > $slowestSql['ms']) {
                $slowestSql = ['ms' => round($query->time, 1), 'sql' => mb_substr($query->sql, 0, 200)];
            }
        });

        $response = $next($request);

        $now = microtime(true);
        $bootMs = defined('LARAVEL_START') ? ($started - LARAVEL_START) * 1000 : 0.0;
        $appMs = ($now - $started) * 1000;
        $totalMs = $bootMs + $appMs;

        $response->headers->set('Server-Timing', sprintf(
            'boot;dur=%.1f, app;dur=%.1f, db;dur=%.1f;desc="%d queries", total;dur=%.1f',
            $bootMs, $appMs - $sqlMs, $sqlMs, $queries, $totalMs,
        ));

        if ($totalMs >= (int) config('chat.slow_request_ms')) {
            Log::channel('performance')->info('slow request', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'total_ms' => round($totalMs),
                'boot_ms' => round($bootMs),
                'sql_ms' => round($sqlMs),
                'queries' => $queries,
                'slowest_sql' => $slowestSql,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ]);
        }

        return $response;
    }
}
