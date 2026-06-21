<?php

namespace Cosmos\LaravelMonitor\Tests\Fakes;

use Cosmos\LaravelMonitor\Storage\ClickHouse\ClickHouseClient;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Created to capture ClickHouse repository SQL without requiring a running ClickHouse server.
 */
class FakeClickHouseClient extends ClickHouseClient
{
    public array $commands = [];

    public array $inserts = [];

    public array $selects = [];

    public array $selectResponses = [];

    public bool $pingResult = true;

    public function __construct(array $config = [])
    {
        parent::__construct(new HttpFactory(), array_merge([
            'database' => 'cosmos_test',
            'retention_days' => 30,
        ], $config));
    }

    public function ping(): bool
    {
        return $this->pingResult;
    }

    public function command(string $sql): void
    {
        $this->commands[] = $sql;
    }

    public function select(string $sql): array
    {
        $this->selects[] = $sql;

        return array_shift($this->selectResponses) ?? [];
    }

    public function insertJsonEachRow(string $table, array $rows): void
    {
        $this->inserts[] = [
            'table' => $table,
            'rows' => $rows,
        ];
    }
}
