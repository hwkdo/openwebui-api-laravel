<?php

declare(strict_types=1);

namespace Hwkdo\OpenwebuiApiLaravel\Prism\Providers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Hwkdo\OpenwebuiApiLaravel\Prism\Providers\Handlers\Text;
use Hwkdo\OpenwebuiApiLaravel\Prism\Providers\Handlers\Stream;

class OpenAICompletions extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $baseUrl,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: [],
                retryAfter: $e->response->header('retry-after') ? (int) $e->response->header('retry-after') : null,
            ),
            default => $this->handleResponseErrors($model, $e),
        };
    }

    protected function handleResponseErrors(string $model, RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'OpenAICompletions',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        // For streaming requests, increase timeout significantly
        $timeout = config('prism.request_timeout', 30);
        $streamingTimeout = max($timeout * 10, 300); // At least 5 minutes for streaming
        
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions(array_merge($options, [
                'stream' => true,
                'timeout' => $streamingTimeout,
                'read_timeout' => $streamingTimeout,
            ]))
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? rtrim($this->baseUrl, '/'));
    }
}
