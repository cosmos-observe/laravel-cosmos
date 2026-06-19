<?php

namespace Cosmos\LaravelMonitor\Tests\Fakes;

/**
 * Created to test Redis-backed telemetry behavior without requiring a real Redis server in CI.
 */
class FakeRedisConnection
{
    public array $sortedSets = [];

    public array $hashes = [];

    public array $expiry = [];

    /**
     * Created to mimic Redis ZADD for timestamp-indexed raw events and rollup indexes.
     */
    public function zadd(string $key, int|float $score, string $member): int
    {
        $isNew = ! isset($this->sortedSets[$key][$member]);
        $this->sortedSets[$key][$member] = $score;

        return $isNew ? 1 : 0;
    }

    /**
     * Created to mimic Redis HSET for event payloads and rollup counters.
     */
    public function hset(string $key, string $field, mixed $value): int
    {
        $isNew = ! isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $isNew ? 1 : 0;
    }

    /**
     * Created to mimic Redis HINCRBY for count rollups.
     */
    public function hincrby(string $key, string $field, int $increment): int
    {
        $this->hashes[$key][$field] = (int) ($this->hashes[$key][$field] ?? 0) + $increment;

        return $this->hashes[$key][$field];
    }

    /**
     * Created to mimic Redis HINCRBYFLOAT for duration sum rollups.
     */
    public function hincrbyfloat(string $key, string $field, float $increment): float
    {
        $this->hashes[$key][$field] = (float) ($this->hashes[$key][$field] ?? 0) + $increment;

        return $this->hashes[$key][$field];
    }

    /**
     * Created to mimic Redis EXPIRE enough for package code paths that set retention TTLs.
     */
    public function expire(string $key, int $seconds): bool
    {
        $this->expiry[$key] = $seconds;

        return true;
    }

    /**
     * Created to mimic Redis ZRANGEBYSCORE for reading sorted event ids and stale rollup keys.
     */
    public function zrangebyscore(string $key, string|int|float $min, string|int|float $max, array $options = []): array
    {
        $min = $this->bound($min, -INF);
        $max = $this->bound($max, INF);
        $members = $this->sortedSets[$key] ?? [];
        asort($members);

        $matched = [];

        foreach (array_keys($members) as $member) {
            if ($members[$member] >= $min && $members[$member] <= $max) {
                $matched[] = $member;
            }
        }

        return $this->applyLimit($matched, $options);
    }

    /**
     * Created to mimic Redis ZREVRANGEBYSCORE for cursor-based descending telemetry reads.
     */
    public function zrevrangebyscore(string $key, string|int|float $max, string|int|float $min, array $options = []): array
    {
        $members = $this->zrangebyscore($key, $min, $max);

        return $this->applyLimit(array_reverse($members), $options);
    }

    /**
     * Created to mimic Redis HGET for reading stored event payloads.
     */
    public function hget(string $key, string $field): mixed
    {
        return $this->hashes[$key][$field] ?? null;
    }

    /**
     * Created to mimic Redis HGETALL for reading rollup buckets.
     */
    public function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    /**
     * Created to mimic Redis ZCOUNT for summary counts.
     */
    public function zcount(string $key, string|int|float $min, string|int|float $max): int
    {
        return count($this->zrangebyscore($key, $min, $max));
    }

    /**
     * Created to mimic Redis HDEL for payload pruning.
     */
    public function hdel(string $key, string $field): int
    {
        if (! isset($this->hashes[$key][$field])) {
            return 0;
        }

        unset($this->hashes[$key][$field]);

        return 1;
    }

    /**
     * Created to mimic Redis ZREMRANGEBYSCORE for raw event and rollup-index pruning.
     */
    public function zremrangebyscore(string $key, string|int|float $min, string|int|float $max): int
    {
        $members = $this->zrangebyscore($key, $min, $max);

        foreach ($members as $member) {
            unset($this->sortedSets[$key][$member]);
        }

        return count($members);
    }

    /**
     * Created to mimic Redis ZRANGE for raw stream cap enforcement and index registry reads.
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        $members = $this->sortedSets[$key] ?? [];
        asort($members);

        if ($stop < 0) {
            $stop = count($members) + $stop;
        }

        return array_slice(array_keys($members), $start, $stop - $start + 1);
    }

    /**
     * Created to mimic Redis ZCARD for raw stream cap enforcement.
     */
    public function zcard(string $key): int
    {
        return count($this->sortedSets[$key] ?? []);
    }

    /**
     * Created to mimic Redis ZREM for deleting raw event ids and index members.
     */
    public function zrem(string $key, string $member): int
    {
        if (! isset($this->sortedSets[$key][$member])) {
            return 0;
        }

        unset($this->sortedSets[$key][$member]);

        return 1;
    }

    /**
     * Created to mimic Redis DEL for rollup hash cleanup.
     */
    public function del(string $key): int
    {
        $deleted = 0;

        if (isset($this->hashes[$key])) {
            unset($this->hashes[$key]);
            $deleted++;
        }

        if (isset($this->sortedSets[$key])) {
            unset($this->sortedSets[$key]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Created to mimic Redis PING for health endpoint tests.
     */
    public function ping(): string
    {
        return 'PONG';
    }

    /**
     * Created to normalize Redis score bounds such as -inf and +inf for fake sorted-set reads.
     */
    protected function bound(string|int|float $value, float $infinite): float
    {
        if (is_string($value) && str_contains(strtolower($value), 'inf')) {
            return $value[0] === '-' ? -INF : INF;
        }

        return (float) $value;
    }

    /**
     * Created to apply Redis-style LIMIT options to fake sorted-set result arrays.
     */
    protected function applyLimit(array $members, array $options): array
    {
        $limit = $options['limit'] ?? $options['LIMIT'] ?? null;

        if (! is_array($limit) || count($limit) !== 2) {
            return $members;
        }

        return array_slice($members, (int) $limit[0], (int) $limit[1]);
    }
}
