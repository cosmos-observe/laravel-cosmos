<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Support\Facades\Storage;

/**
 * Created to sample configured Laravel filesystem disks without requiring write probes or unbounded scans.
 */
class StorageSnapshotService
{
    public function __construct(
        protected TelemetryRepository $telemetry,
        protected NotificationService $notifications
    ) {
    }

    /**
     * Created to sample every configured disk and return the payloads recorded into telemetry.
     */
    public function sampleConfiguredDisks(): array
    {
        $snapshots = [];

        foreach ($this->configuredDisks() as $disk) {
            $snapshots[] = $this->sampleDisk($disk);
        }

        return $snapshots;
    }

    /**
     * Created to sample one disk with local byte/file totals where available and bounded listability checks elsewhere.
     */
    public function sampleDisk(string $disk): array
    {
        $previous = $this->latestDiskSnapshot($disk);

        try {
            $payload = $this->diskPayload($disk, $previous);
        } catch (\Throwable $exception) {
            $payload = [
                'type' => 'storage_disk',
                'event' => 'sampled',
                'disk' => $disk,
                'driver' => $this->diskDriver($disk),
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
                'total_files' => null,
                'file_count_delta' => null,
                'total_bytes' => null,
                'disk_total_bytes' => null,
                'disk_free_bytes' => null,
                'used_percent' => null,
                'scanned_files' => 0,
                'scan_truncated' => false,
            ];
        }

        $this->telemetry->recordEvent('storage', $payload);
        $this->dispatchTransitionNotification($disk, $previous['status'] ?? null, $payload);

        return $payload;
    }

    /**
     * Created to assemble a disk payload while separating local metrics from remote listability checks.
     */
    protected function diskPayload(string $disk, ?array $previous): array
    {
        $driver = $this->diskDriver($disk);
        $localRoot = $this->localRoot($disk);

        if ($localRoot !== null) {
            $scan = $this->scanLocalRoot($localRoot);
            $diskTotal = @disk_total_space($localRoot) ?: null;
            $diskFree = @disk_free_space($localRoot) ?: null;
            $usedPercent = $diskTotal && $diskFree !== null
                ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 2)
                : null;
            $totalFiles = $scan['total_files'];

            return [
                'type' => 'storage_disk',
                'event' => 'sampled',
                'disk' => $disk,
                'driver' => $driver,
                'path' => $localRoot,
                'status' => $this->statusForUsedPercent($usedPercent),
                'total_files' => $totalFiles,
                'file_count_delta' => is_numeric($previous['total_files'] ?? null) ? $totalFiles - (int) $previous['total_files'] : 0,
                'total_bytes' => $scan['total_bytes'],
                'disk_total_bytes' => $diskTotal !== null ? (int) $diskTotal : null,
                'disk_free_bytes' => $diskFree !== null ? (int) $diskFree : null,
                'used_percent' => $usedPercent,
                'scanned_files' => $scan['scanned_files'],
                'scan_truncated' => $scan['scan_truncated'],
            ];
        }

        $listed = Storage::disk($disk)->files('', false);

        return [
            'type' => 'storage_disk',
            'event' => 'sampled',
            'disk' => $disk,
            'driver' => $driver,
            'status' => 'ok',
            'total_files' => null,
            'file_count_delta' => null,
            'total_bytes' => null,
            'disk_total_bytes' => null,
            'disk_free_bytes' => null,
            'used_percent' => null,
            'scanned_files' => count($listed),
            'scan_truncated' => false,
            'listable' => true,
        ];
    }

    /**
     * Created to count local files and bytes while stopping at the configured safety limit.
     */
    protected function scanLocalRoot(string $root): array
    {
        if (! is_dir($root)) {
            throw new \RuntimeException("Storage path [{$root}] is not a readable directory.");
        }

        $maxFiles = max(1, (int) config('cosmos-monitor.storage_monitor.max_files_per_disk', 50000));
        $totalFiles = 0;
        $totalBytes = 0;
        $truncated = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $totalFiles++;
            $totalBytes += max(0, (int) $file->getSize());

            if ($totalFiles >= $maxFiles) {
                $truncated = true;
                break;
            }
        }

        return [
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
            'scanned_files' => $totalFiles,
            'scan_truncated' => $truncated,
        ];
    }

    /**
     * Created to find local Laravel disk roots without touching remote adapters.
     */
    protected function localRoot(string $disk): ?string
    {
        $config = (array) config("filesystems.disks.{$disk}", []);

        if (($config['driver'] ?? null) !== 'local') {
            return null;
        }

        return isset($config['root']) ? (string) $config['root'] : null;
    }

    /**
     * Created to return a compact status from configured disk-pressure thresholds.
     */
    protected function statusForUsedPercent(?float $usedPercent): string
    {
        if ($usedPercent === null) {
            return 'ok';
        }

        if ($usedPercent >= (float) config('cosmos-monitor.storage_monitor.critical_used_percent', 95)) {
            return 'critical';
        }

        if ($usedPercent >= (float) config('cosmos-monitor.storage_monitor.warning_used_percent', 85)) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Created to read the latest disk sample so file deltas and status transitions stay lightweight.
     */
    protected function latestDiskSnapshot(string $disk): ?array
    {
        $result = $this->telemetry->listEvents('storage', [
            'disk' => $disk,
            'page' => 1,
            'per_page' => 1,
            'scan_limit' => 20,
        ]);

        return $result['data'][0] ?? null;
    }

    /**
     * Created to notify only when a disk changes status after it has at least one previous sample.
     */
    protected function dispatchTransitionNotification(string $disk, ?string $previousStatus, array $payload): void
    {
        $currentStatus = (string) ($payload['status'] ?? 'unknown');

        if ($previousStatus === null || $previousStatus === $currentStatus) {
            return;
        }

        $this->notifications->dispatch('storage_status_transition', [
            'severity' => in_array($currentStatus, ['critical', 'unavailable'], true) ? 'critical' : 'warning',
            'disk' => $disk,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
            'used_percent' => $payload['used_percent'] ?? null,
        ]);
    }

    /**
     * Created to normalize configured disk names and remove duplicates.
     */
    protected function configuredDisks(): array
    {
        $disks = (array) config('cosmos-monitor.storage_monitor.disks', ['local', 'public']);
        $disks = array_filter(array_map(static fn ($disk) => trim((string) $disk), $disks));

        return array_values(array_unique($disks ?: ['local', 'public']));
    }

    /**
     * Created to surface adapter type in dashboard rows without assuming local paths.
     */
    protected function diskDriver(string $disk): ?string
    {
        return config("filesystems.disks.{$disk}.driver");
    }
}
