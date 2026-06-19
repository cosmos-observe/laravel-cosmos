<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\ExceptionRecorder;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Created to expose disabled-by-default diagnostic actions for E2E testing and controlled dashboard probes.
 */
class DiagnosticsController extends Controller
{
    /**
     * Created to write a controlled log record through Laravel's logging pipeline for monitor verification.
     */
    public function logTest(Request $request): JsonResponse
    {
        $this->abortIfActionsDisabled();

        $validated = $request->validate([
            'level' => ['sometimes', 'string', 'in:debug,info,notice,warning,error,critical,alert,emergency'],
            'message' => ['sometimes', 'string', 'max:500'],
        ]);

        $level = $validated['level'] ?? 'warning';
        $message = $validated['message'] ?? 'Cosmos monitor diagnostic log';

        Log::log($level, $message, ['source' => 'cosmos-monitor-diagnostics']);

        return $this->envelope([
            'logged' => true,
            'level' => $level,
            'message' => $message,
        ]);
    }

    /**
     * Created to record a controlled exception without crashing the host application during API verification.
     */
    public function exceptionTest(Request $request, ExceptionRecorder $exceptions): JsonResponse
    {
        $this->abortIfActionsDisabled();

        $validated = $request->validate([
            'message' => ['sometimes', 'string', 'max:500'],
        ]);

        $exception = new \RuntimeException($validated['message'] ?? 'Cosmos monitor diagnostic exception');
        $exceptions->record($exception);

        return $this->envelope([
            'recorded' => true,
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Created to execute a tiny DB probe and optionally record a synthetic slow-query event for dashboard testing.
     */
    public function databaseTestQuery(Request $request, RedisTelemetryRepository $telemetry): JsonResponse
    {
        $this->abortIfActionsDisabled();

        $validated = $request->validate([
            'mode' => ['sometimes', 'string', 'in:fast,slow,critical'],
        ]);

        $started = microtime(true);
        DB::select('select 1 as cosmos_monitor_probe');
        $durationMs = round((microtime(true) - $started) * 1000, 2);
        $mode = $validated['mode'] ?? 'fast';

        if ($mode !== 'fast') {
            $durationMs = $mode === 'critical' ? 850.0 : 260.0;
            $telemetry->recordEvent('database', [
                'type' => 'database_query',
                'event' => 'diagnostic',
                'connection' => config('database.default'),
                'duration_ms' => $durationMs,
                'sql' => 'select sleep-like diagnostic probe',
                'statement_hash' => sha1('cosmos-monitor-diagnostic-' . $mode),
                'status' => $mode,
            ]);
        }

        return $this->envelope([
            'queried' => true,
            'mode' => $mode,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Created to keep diagnostic mutation endpoints unavailable in production unless explicitly enabled.
     */
    protected function abortIfActionsDisabled(): void
    {
        abort_unless((bool) config('cosmos-monitor.actions.enabled', false), 403, 'Cosmos Monitor actions are disabled.');
    }
}
