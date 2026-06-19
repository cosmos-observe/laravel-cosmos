<?php

namespace Cosmos\LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Created to optionally protect monitor APIs for dashboards without forcing a specific Laravel auth stack.
 */
class EnsureMonitorApiToken
{
    /**
     * Created to compare bearer or header tokens only when COSMOS_MONITOR_API_TOKEN is configured.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('cosmos-monitor.api_token');

        if (! $expected) {
            return $next($request);
        }

        $provided = $request->bearerToken() ?: $request->header('X-Cosmos-Monitor-Token');

        if (! is_string($provided) || ! hash_equals((string) $expected, $provided)) {
            return response()->json([
                'message' => 'Invalid Cosmos Monitor API token.',
            ], 401);
        }

        return $next($request);
    }
}
