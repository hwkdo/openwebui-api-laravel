<?php

declare(strict_types=1);

namespace Hwkdo\OpenwebuiApiLaravel\Prism\Providers\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $choice = data_get($data, 'choices.0');
        $message = data_get($choice, 'message', []);

        $responseMessage = new AssistantMessage(
            data_get($message, 'content') ?? '',
            $this->mapToolCalls(data_get($message, 'tool_calls', [])),
        );

        $request->addMessage($responseMessage);

        // Check for tool calls first
        if (! empty(data_get($message, 'tool_calls'))) {
            return $this->handleToolCalls($data, $request);
        }

        return match ($this->mapFinishReason($choice)) {
            FinishReason::Stop => $this->handleStop($data, $request),
            FinishReason::Length => $this->handleStop($data, $request),
            default => throw new PrismException('OpenAICompletions: unknown finish reason'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this
            ->client
            ->post('v1/chat/completions', [
                'model' => $request->model(),
                'messages' => $this->mapMessages(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )),
                'tools' => $this->mapTools($request->tools()),
                'stream' => false,
                ...Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'max_tokens' => $request->maxTokens(),
                    'top_p' => $request->topP(),
                ]),
            ]);

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if (! isset($data['choices']) || ! is_array($data['choices']) || empty($data['choices'])) {
            throw new PrismException('OpenAICompletions: Invalid response format - missing choices');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): Response
    {
        $choice = data_get($data, 'choices.0');
        $message = data_get($choice, 'message', []);

        $toolResults = $this->callTools(
            $request->tools(),
            $this->mapToolCalls(data_get($message, 'tool_calls', [])),
        );

        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->addStep($data, $request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request): Response
    {
        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $choice = data_get($data, 'choices.0');
        $message = data_get($choice, 'message', []);

        $this->responseBuilder->addStep(new Step(
            text: data_get($message, 'content') ?? '',
            finishReason: $this->mapFinishReason($choice),
            toolCalls: $this->mapToolCalls(data_get($message, 'tool_calls', []) ?? []),
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens', 0),
                data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    protected function mapFinishReason(array $choice): FinishReason
    {
        $finishReason = data_get($choice, 'finish_reason', 'stop');

        return match ($finishReason) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls' => FinishReason::ToolCalls,
            default => FinishReason::Stop,
        };
    }

    /**
     * @param  array<int, \Prism\Prism\ValueObjects\Messages\Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessages(array $messages): array
    {
        return array_map(function ($message) {
            $role = match (true) {
                $message instanceof \Prism\Prism\ValueObjects\Messages\UserMessage => 'user',
                $message instanceof \Prism\Prism\ValueObjects\Messages\AssistantMessage => 'assistant',
                $message instanceof \Prism\Prism\ValueObjects\Messages\SystemMessage => 'system',
                $message instanceof \Prism\Prism\ValueObjects\Messages\ToolResultMessage => 'tool',
                default => 'user',
            };

            $mapped = [
                'role' => $role,
            ];

            if ($message instanceof \Prism\Prism\ValueObjects\Messages\UserMessage) {
                $mapped['content'] = $message->content;
            } elseif ($message instanceof \Prism\Prism\ValueObjects\Messages\SystemMessage) {
                $mapped['content'] = $message->content;
            } elseif ($message instanceof \Prism\Prism\ValueObjects\Messages\AssistantMessage) {
                $mapped['content'] = $message->content;
                if (! empty($message->toolCalls)) {
                    $mapped['tool_calls'] = array_map(fn ($toolCall) => [
                        'id' => $toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->name,
                            'arguments' => is_string($toolCall->arguments) 
                                ? $toolCall->arguments 
                                : json_encode($toolCall->arguments),
                        ],
                    ], $message->toolCalls);
                }
            } elseif ($message instanceof \Prism\Prism\ValueObjects\Messages\ToolResultMessage) {
                // For tool messages, we need to create one message per tool result
                // OpenAI expects tool messages with tool_call_id
                if (! empty($message->toolResults)) {
                    $toolResult = $message->toolResults[0];
                    $mapped['tool_call_id'] = $toolResult->toolCallId;
                    $mapped['content'] = is_string($toolResult->result) 
                        ? $toolResult->result 
                        : json_encode($toolResult->result);
                }
            }

            return $mapped;
        }, $messages);
    }

    /**
     * @param  array<int, \Prism\Prism\ValueObjects\Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        return array_map(fn ($tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersAsArray(),
            ],
        ], $tools);
    }
}
