<?php

namespace Cosmos\LaravelMonitor\Http\Client;

use Cosmos\LaravelMonitor\Services\ExternalHttpRequestRecorder;

/**
 * Created as an opt-in Guzzle middleware for apps that make direct Guzzle calls instead of Laravel's Http client.
 */
class ExternalRequestGuzzleMiddleware
{
    public function __construct(
        protected ExternalHttpRequestRecorder $recorder
    ) {
    }

    /**
     * Created to wrap a Guzzle handler and record success or rejection metadata without changing caller behavior.
     */
    public function __invoke(callable $handler): callable
    {
        return function (object $request, array $options = []) use ($handler) {
            $startedAt = hrtime(true);
            $source = isset($options['cosmos_monitor_source']) ? (string) $options['cosmos_monitor_source'] : 'guzzle_middleware';
            $promise = $handler($request, $options);

            return $promise->then(
                function (object $response) use ($request, $startedAt, $source) {
                    $this->recorder->recordPsrRequest($request, $response, $this->elapsedMs($startedAt), null, $source);

                    return $response;
                },
                function (mixed $reason) use ($request, $startedAt, $source) {
                    $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                    $exception = $reason instanceof \Throwable ? $reason : new \RuntimeException(is_scalar($reason) ? (string) $reason : 'Guzzle request rejected');
                    $this->recorder->recordPsrRequest($request, is_object($response) ? $response : null, $this->elapsedMs($startedAt), $exception, $source);

                    throw $exception;
                }
            );
        };
    }

    protected function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1000000;
    }
}
