<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Models\ExternalService;
use Cosmos\LaravelMonitor\Services\ExternalServiceChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to manage user-defined external service dependencies and run manual probes.
 */
class ExternalServiceController extends Controller
{
    /**
     * Created to return the service registry with latest health snapshots.
     */
    public function index(): JsonResponse
    {
        return $this->envelope(ExternalService::query()->orderBy('name')->get()->values());
    }

    /**
     * Created to register a new external HTTP dependency by name and URL.
     */
    public function store(Request $request): JsonResponse
    {
        $service = ExternalService::query()->create($this->validatedServicePayload($request));

        return $this->envelope($service->fresh(), status: 201);
    }

    /**
     * Created to update an existing service definition or enabled flag.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $service = ExternalService::query()->findOrFail($id);
        $service->update($this->validatedServicePayload($request, true));

        return $this->envelope($service->fresh());
    }

    /**
     * Created to remove a service definition from future scheduled checks.
     */
    public function destroy(int $id): JsonResponse
    {
        ExternalService::query()->findOrFail($id)->delete();

        return $this->envelope(['deleted' => true]);
    }

    /**
     * Created to let dashboard operators run an immediate probe for one service.
     */
    public function check(int $id, ExternalServiceChecker $checker): JsonResponse
    {
        $service = ExternalService::query()->findOrFail($id);

        return $this->envelope([
            'service' => $service->fresh(),
            'check' => $checker->check($service),
        ]);
    }

    /**
     * Created to keep create and update validation aligned while supporting partial updates.
     */
    protected function validatedServicePayload(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'url' => [$partial ? 'sometimes' : 'required', 'url', 'max:2048'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }
}
