<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Exceptions\AuthenticationException;
use Bramato\LaravelAi\Exceptions\InvalidResponseException;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Illuminate\Support\Facades\Http;

// Note: uses(TestCase::class) is applied globally via tests/Pest.php

$apiVersion = '2023-06-01';
$model = 'claude-test';
$apiKey = 'test-claude-key';
$baseUri = 'https://api.anthropic.com/v1';
$endpoint = "{$baseUri}/messages";

beforeEach(function () use ($apiVersion, $model, $apiKey, $baseUri) {
    Http::preventStrayRequests();
    config()->set('laravel-ai.default', 'claude');
    config()->set('laravel-ai.providers.claude.api_key', $apiKey);
    config()->set('laravel-ai.providers.claude.model', $model);
    config()->set('laravel-ai.providers.claude.options', [
        'version' => $apiVersion,
        'base_uri' => $baseUri,
    ]);
});

it('can get successful response via interface', function () use ($endpoint, $model, $apiKey, $apiVersion) {
    // Uses global helper: getFakeClaudeSuccessResponse
    $fakeResponseData = getFakeClaudeSuccessResponse(model: $model);
    Http::fake([
        $endpoint => Http::response($fakeResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Hello Claude']);
    $response = $client->chat($request);

    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->content)->toBe('Claude test content.') // Matches helper default
        ->and($response->model)->toBe($model)
        ->and($response->id)->toBe('msg_test_123') // Matches helper default
        ->and($response->finishReason)->toBe('end_turn') // Matches helper default
        ->and($response->usage['input_tokens'])->toBe(12) // Matches helper default
        ->and($response->usage['output_tokens'])->toBe(22) // Matches helper default
        ->and($response->isJson)->toBeFalse()
        ->and($response->rawResponse)->toBe($fakeResponseData);

    Http::assertSentCount(1);
    Http::assertSent(function ($httpRequest) use ($endpoint, $apiKey, $apiVersion, $model) {
        return $httpRequest->url() === $endpoint &&
            $httpRequest->method() === 'POST' &&
            $httpRequest->hasHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $apiVersion,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]) &&
            isset($httpRequest->data()['model']) && $httpRequest->data()['model'] === $model &&
            isset($httpRequest->data()['max_tokens']) && is_int($httpRequest->data()['max_tokens']) &&
            isset($httpRequest->data()['messages'][0]['role']) && $httpRequest->data()['messages'][0]['role'] === 'user';
    });
});

it('can get successful response with system prompt', function () use ($endpoint, $model) {
    // Uses global helper: getFakeClaudeSuccessResponse
    $fakeResponseData = getFakeClaudeSuccessResponse(model: $model, content: 'Understood system prompt.');
    Http::fake([
        $endpoint => Http::response($fakeResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'User query',
        'systemMessage' => 'You are a helpful assistant.',
    ]);
    $response = $client->chat($request);

    expect($response->content)->toBe('Understood system prompt.');

    Http::assertSent(function ($httpRequest) {
        return isset($httpRequest->data()['system']) && $httpRequest->data()['system'] === 'You are a helpful assistant.';
    });
});

it('throws AuthenticationException on 401 error', function () use ($endpoint) {
    // Uses global helper: getFakeClaudeErrorResponse
    $errorResponse = getFakeClaudeErrorResponse('authentication_error', 'Invalid API Key');
    Http::fake([
        $endpoint => Http::response($errorResponse, 401),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Claude Auth 401']);

    // Expect specific message from ClaudeClient handler
    expect(fn() => $client->chat($request))
        ->toThrow(AuthenticationException::class, 'Claude Authentication failed - Invalid API Key');
});

it('throws AuthenticationException on 403 error', function () use ($endpoint) {
    // Uses global helper: getFakeClaudeErrorResponse
    $errorResponse = getFakeClaudeErrorResponse('permission_error', 'You do not have permission to use this model');
    Http::fake([
        $endpoint => Http::response($errorResponse, 403),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Claude Auth 403']);

    // Expect specific message from ClaudeClient handler
    expect(fn() => $client->chat($request))
        ->toThrow(AuthenticationException::class, 'Claude Forbidden - Check Permissions or Request Details');
});

it('throws LlmApiException on 400 error (e.g., invalid request)', function () use ($endpoint) {
    // Uses global helper: getFakeClaudeErrorResponse
    $errorResponse = getFakeClaudeErrorResponse('invalid_request_error', 'max_tokens must be positive');
    Http::fake([
        $endpoint => Http::response($errorResponse, 400),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Claude Bad Request', 'options' => ['max_tokens' => -1]]); // Let buildPayload catch this potentially

    // The error should be caught by buildPayload validation first
    expect(fn() => $client->chat($request))
        ->toThrow(LlmApiException::class, 'Invalid option: max_tokens must be a positive integer for Claude.');

    // If buildPayload didn't catch it, the API error would be:
    // ->toThrow(LlmApiException::class, 'Claude API Error - Bad Request (invalid_request_error): max_tokens must be positive');
});

it('throws LlmApiException on invalid message sequence (consecutive user messages in history)', function () {
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'This prompt is fine',
        'history' => [
            ['role' => 'user', 'content' => 'First user message'],
            ['role' => 'user', 'content' => 'Second consecutive user message'], // Invalid
        ],
    ]);

    expect(fn() => $client->chat($request))
        ->toThrow(LlmApiException::class, 'Invalid message sequence for Claude: Consecutive messages found from role \'user\'. History must alternate between \'user\' and \'assistant\'.');
});

it('throws LlmApiException on invalid message sequence (last history message is user)', function () {
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Third user message',
        'history' => [
            ['role' => 'user', 'content' => 'First user message'],
            ['role' => 'assistant', 'content' => 'Model response'],
            ['role' => 'user', 'content' => 'Second user message'], // Invalid history - last item cannot be user
        ],
    ]);

    expect(fn() => $client->chat($request))
        ->toThrow(LlmApiException::class, 'Invalid message sequence for Claude: The last message in the history array must be from role \'assistant\' before adding the final user prompt.');
});

it('throws InvalidResponseException on malformed success response', function () use ($endpoint) {
    $malformedResponseData = [
        'id' => 'msg_malformed',
        'type' => 'message',
        // Missing 'content' or other required fields like model, role, usage, stop_reason
    ];
    Http::fake([
        $endpoint => Http::response($malformedResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Claude Malformed']);

    expect(fn() => $client->chat($request))
        ->toThrow(InvalidResponseException::class, 'Invalid response structure received from Claude API (missing expected fields).');
});

// Note: JSON mode tests are handled in JsonModeTest.php 