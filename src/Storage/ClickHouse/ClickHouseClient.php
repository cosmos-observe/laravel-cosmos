<?php

namespace Cosmos\LaravelMonitor\Storage\ClickHouse;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Created to talk to ClickHouse over its HTTP API without adding a dedicated PHP driver dependency.
 */
class ClickHouseClient
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config
    ) {
    }

    /**
     * Created to verify ClickHouse's lightweight HTTP root response for health checks.
     */
    public function ping(): bool
    {
        try {
            $response = $this->http->timeout($this->timeoutSeconds())->get($this->url());

            return $response->successful() && trim($response->body()) === 'Ok.';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Created to execute DDL or other non-tabular ClickHouse statements.
     */
    public function command(string $sql): void
    {
        $params = preg_match('/^\s*CREATE\s+DATABASE\b/i', $sql) ? [] : $this->defaultParams();

        $this->postSql($sql, $params);
    }

    /**
     * Created to run SELECT statements and return ClickHouse JSON rows.
     */
    public function select(string $sql): array
    {
        $response = $this->postSql($this->ensureJsonFormat($sql));
        $decoded = json_decode($response, true);

        return is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : [];
    }

    /**
     * Created to insert JSONEachRow batches through ClickHouse's HTTP interface.
     */
    public function insertJsonEachRow(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $payload = implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES) ?: '{}',
            $rows
        )) . "\n";

        $query = sprintf(
            'INSERT INTO %s FORMAT JSONEachRow',
            $this->qualifiedTable($table)
        );

        $params = array_merge($this->defaultParams(), $this->insertSettings(), [
            'query' => $query,
        ]);

        $response = $this->request()
            ->withBody($payload, 'application/x-ndjson')
            ->post($this->urlWithParams($params));

        if (! $response->successful()) {
            throw new \RuntimeException('ClickHouse insert failed: ' . $response->body(), $response->status());
        }
    }

    /**
     * Created to quote a configured identifier such as a database, table, or column name.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Created to quote scalar SQL string values for ClickHouse queries.
     */
    public function quoteString(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    /**
     * Created to build a fully qualified table name in the configured ClickHouse database.
     */
    public function qualifiedTable(string $table): string
    {
        return $this->quoteIdentifier($this->database()) . '.' . $this->quoteIdentifier($table);
    }

    /**
     * Created to expose the configured database for schema DDL.
     */
    public function database(): string
    {
        return (string) ($this->config['database'] ?? 'cosmos_monitor');
    }

    /**
     * Created to execute raw SQL against ClickHouse and return the response body.
     */
    protected function postSql(string $sql, ?array $params = null): string
    {
        $response = $this->request()
            ->withBody($sql, 'text/plain')
            ->post($this->urlWithParams($params ?? $this->defaultParams()));

        if (! $response->successful()) {
            throw new \RuntimeException('ClickHouse query failed: ' . $response->body(), $response->status());
        }

        return $response->body();
    }

    /**
     * Created to add FORMAT JSON to SELECT queries that do not already specify an output format.
     */
    protected function ensureJsonFormat(string $sql): string
    {
        return preg_match('/\bFORMAT\s+JSON\b/i', $sql) ? $sql : rtrim($sql, " \t\n\r\0\x0B;") . ' FORMAT JSON';
    }

    /**
     * Created to configure authentication, timeouts, and optional response compression in one place.
     */
    protected function request(): mixed
    {
        $request = $this->http
            ->timeout($this->timeoutSeconds())
            ->accept('application/json');

        $username = (string) ($this->config['username'] ?? 'default');
        $password = (string) ($this->config['password'] ?? '');

        if ($username !== '' || $password !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return $request;
    }

    /**
     * Created to keep ClickHouse URL normalization local to the client.
     */
    protected function url(): string
    {
        return rtrim((string) ($this->config['url'] ?? 'http://127.0.0.1:8123'), '/');
    }

    /**
     * Created to keep ClickHouse HTTP query parameters in the URL while preserving the raw POST body.
     */
    protected function urlWithParams(array $params): string
    {
        if ($params === []) {
            return $this->url();
        }

        return $this->url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Created to send the configured database as an HTTP parameter.
     */
    protected function defaultParams(): array
    {
        return [
            'database' => $this->database(),
        ];
    }

    /**
     * Created to apply reliable async insert settings per insert request.
     */
    protected function insertSettings(): array
    {
        return [
            'async_insert' => $this->boolParam($this->config['async_insert'] ?? true),
            'wait_for_async_insert' => $this->boolParam($this->config['wait_for_async_insert'] ?? true),
        ];
    }

    protected function boolParam(mixed $value): int
    {
        return (bool) $value ? 1 : 0;
    }

    protected function timeoutSeconds(): float
    {
        return max(0.1, (float) ($this->config['timeout_seconds'] ?? 2.0));
    }
}
