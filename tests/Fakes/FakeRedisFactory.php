<?php

namespace Cosmos\LaravelMonitor\Tests\Fakes;

use Illuminate\Contracts\Redis\Factory;

/**
 * Created to provide the Redis factory contract for repository tests without external services.
 */
class FakeRedisFactory implements Factory
{
    /**
     * Created to keep all fake Redis connections backed by the same in-memory store.
     */
    public function __construct(
        public FakeRedisConnection $connection = new FakeRedisConnection()
    ) {
    }

    /**
     * Created to satisfy Laravel's Redis factory contract and ignore named connections during tests.
     */
    public function connection($name = null): FakeRedisConnection
    {
        return $this->connection;
    }
}
