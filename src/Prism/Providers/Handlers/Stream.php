<?php

declare(strict_types=1);

namespace Hwkdo\OpenwebuiApiLaravel\Prism\Providers\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools;

    protected array $state = [
        'streamStarted' => false,
        'stepStarted' => false,
        'textStarted' => false,
        'messageId' => null,
        'promptTokens' => 0,
        'completionTokens' => 0,
        'toolCalls' => [],
    ];

    public function __construct(protected PendingRequest $client)
    {
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        if ($depth === 0) {
            $this->resetState();
        }

        $text = '';
        $toolCalls = [];
        $lineCount = 0;
        $emptyLineCount = 0;
        $maxEmptyLines = 10; // Allow some empty lines before giving up

        while (! $response->getBody()->eof()) {
            $lineCount++;
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                $emptyLineCount++;
                // If we've read many empty lines in a row, wait a bit and check again
                if ($emptyLineCount > $maxEmptyLines) {
                    // Small delay to allow stream to catch up
                    usleep(100000); // 100ms
                    $emptyLineCount = 0;
                    
                    // If stream is still at EOF after delay, break
                    if ($response->getBody()->eof()) {
                        break;
                    }
                }
                continue;
            }
            
            // Reset empty line counter when we get valid data
            $emptyLineCount = 0;

            // Emit stream start event if not already started
            if (! $this->state['streamStarted']) {
                $this->state['streamStarted'] = true;
                $this->state['messageId'] = EventID::generate();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: 'openwebui-completions'
                );
            }

            // Emit step start event once per step
            if (! $this->state['stepStarted']) {
                $this->state['stepStarted'] = true;

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            // Accumulate token counts
            if (isset($data['usage'])) {
                $this->state['promptTokens'] = data_get($data, 'usage.prompt_tokens', 0);
                $this->state['completionTokens'] = data_get($data, 'usage.completion_tokens', 0);
            }

            $choice = data_get($data, 'choices.0');
            if (! $choice) {
                continue;
            }

            $delta = data_get($choice, 'delta', []);

            // Handle tool calls
            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $toolCall) {
                    $index = data_get($toolCall, 'index', 0);
                    if (! isset($toolCalls[$index])) {
                        $toolCalls[$index] = [
                            'id' => '',
                            'name' => '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($toolCall['id'])) {
                        $toolCalls[$index]['id'] = $toolCall['id'];
                    }

                    if (isset($toolCall['function']['name'])) {
                        $toolCalls[$index]['name'] = $toolCall['function']['name'];
                    }

                    if (isset($toolCall['function']['arguments'])) {
                        $toolCalls[$index]['arguments'] .= $toolCall['function']['arguments'];
                    }
                }
            }

            // Handle text content
            $content = data_get($delta, 'content', '');
            if ($content !== '') {
                if (! $this->state['textStarted']) {
                    $this->state['textStarted'] = true;
                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state['messageId']
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state['messageId']
                );
            }

            // Handle completion
            $finishReason = data_get($choice, 'finish_reason');
            if ($finishReason !== null) {
                // Emit text complete if we had text content
                if ($this->state['textStarted']) {
                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state['messageId']
                    );
                }

                // Handle tool calls if present
                if (! empty($toolCalls) && $finishReason === 'tool_calls') {
                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);
                    return;
                }

                // Emit step finish before stream end
                yield new StepFinishEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );

                // Emit stream end event with usage
                yield $this->emitStreamEndEvent($finishReason);

                return;
            }
        }
        
        // If we exit the loop without a finish_reason, check if we have content
        // This handles cases where the stream ends without explicit finish_reason
        if ($text !== '' || ! empty($toolCalls)) {
            // Emit text complete if we had text content
            if ($this->state['textStarted'] && $text !== '') {
                yield new TextCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state['messageId']
                );
            }
            
            // Handle tool calls if present
            if (! empty($toolCalls)) {
                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);
                return;
            }
            
            // Emit step finish and stream end
            yield new StepFinishEvent(
                id: EventID::generate(),
                timestamp: time()
            );
            
            yield $this->emitStreamEndEvent('stop');
        }
    }

    protected function emitStreamEndEvent(string $finishReason): StreamEndEvent
    {
        $mappedFinishReason = match ($finishReason) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls' => FinishReason::ToolCalls,
            default => FinishReason::Stop,
        };

        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $mappedFinishReason,
            usage: new Usage(
                promptTokens: $this->state['promptTokens'],
                completionTokens: $this->state['completionTokens']
            )
        );
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (trim($line) === '') {
            return null;
        }

        // Handle SSE format: "data: {...}" or just "{...}"
        $trimmedLine = trim($line);
        
        // Remove "data: " prefix if present
        if (str_starts_with($trimmedLine, 'data: ')) {
            $jsonData = substr($trimmedLine, 6);
        } else {
            // Some APIs send JSON directly without "data: " prefix
            $jsonData = $trimmedLine;
        }

        if ($jsonData === '[DONE]' || $jsonData === '') {
            return null;
        }

        try {
            return json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            // Log the problematic line for debugging but don't throw immediately
            // Some lines might be empty or contain non-JSON data
            if (strlen($jsonData) > 0 && $jsonData !== '[DONE]') {
                throw new PrismStreamDecodeException('OpenAICompletions', $e);
            }
            return null;
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth
    ): Generator {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        // Emit tool call events for each completed tool call
        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state['messageId']
            );
        }

        // Execute tools and emit results
        $toolResults = [];
        yield from $this->callToolsAndYieldEvents($request->tools(), $mappedToolCalls, $this->state['messageId'], $toolResults);

        // Add messages for next turn
        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        // Emit step finish after tool calls
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        // Continue streaming if within step limit
        $depth++;
        if ($depth < $request->maxSteps()) {
            $this->resetState();
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent('stop');
        }
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('v1/chat/completions', [
                'model' => $request->model(),
                'messages' => $this->mapMessages(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )),
                'tools' => $this->mapTools($request->tools()),
                'stream' => true,
                ...Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'max_tokens' => $request->maxTokens(),
                    'top_p' => $request->topP(),
                ]),
            ]);

        return $response;
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'name') ?? '',
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
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

    protected function resetState(): void
    {
        $this->state = [
            'streamStarted' => false,
            'stepStarted' => false,
            'textStarted' => false,
            'messageId' => null,
            'promptTokens' => 0,
            'completionTokens' => 0,
            'toolCalls' => [],
        ];
    }
}
