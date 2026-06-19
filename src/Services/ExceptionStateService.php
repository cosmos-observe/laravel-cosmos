<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Models\MonitorExceptionState;

/**
 * Created to keep dashboard exception workflow state in durable storage while exception events remain Redis-first.
 */
class ExceptionStateService
{
    /**
     * Created to overlay active, resolved, or snoozed state on Redis exception events by hash.
     */
    public function apply(array $events): array
    {
        $hashes = [];

        foreach ($events as $event) {
            if (isset($event['hash'])) {
                $hashes[] = (string) $event['hash'];
            }
        }

        $hashes = array_values(array_filter(array_unique($hashes)));

        if ($hashes === []) {
            return $events;
        }

        try {
            $states = MonitorExceptionState::query()
                ->whereIn('hash', $hashes)
                ->get()
                ->keyBy('hash');
        } catch (\Throwable) {
            return $events;
        }

        foreach ($events as $index => $event) {
            $hash = (string) ($event['hash'] ?? '');
            $state = $states->get($hash);

            $events[$index]['workflow_status'] = $state?->status ?? 'active';
            $events[$index]['snoozed_until'] = $state?->snoozed_until?->toISOString();
            $events[$index]['state_note'] = $state?->note;
        }

        return $events;
    }

    /**
     * Created to update one exception hash state from the monitoring API.
     */
    public function update(string $hash, array $payload, ?string $updatedBy = null): array
    {
        $state = MonitorExceptionState::query()->updateOrCreate(
            ['hash' => $hash],
            [
                'status' => $payload['status'] ?? 'active',
                'snoozed_until' => $payload['snoozed_until'] ?? null,
                'note' => $payload['note'] ?? null,
                'updated_by' => $updatedBy,
            ]
        );

        return $state->toArray();
    }
}
