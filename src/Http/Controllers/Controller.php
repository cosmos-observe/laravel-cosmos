<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Support\TelemetryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Created to keep all monitoring API responses in the same envelope shape.
 */
abstract class Controller extends BaseController
{
    /**
     * Created to parse shared filter, sort, and pagination parameters consistently for telemetry endpoints.
     */
    protected function filters(Request $request, array $overrides = []): array
    {
        return TelemetryQuery::fromRequest($request, $overrides);
    }

    /**
     * Created to return API payloads with data, meta, and links keys for panel integration.
     */
    protected function envelope(mixed $data, array $meta = [], array $links = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
            'links' => $links,
        ], $status);
    }

    /**
     * Created to pass repository list results through without each controller duplicating envelope logic.
     */
    protected function telemetryEnvelope(array $result): JsonResponse
    {
        return $this->envelope($result['data'] ?? [], $result['meta'] ?? [], $result['links'] ?? []);
    }
}
