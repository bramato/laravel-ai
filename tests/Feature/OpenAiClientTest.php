<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Exceptions\AuthenticationException;
use Bramato\LaravelAi\Exceptions\InvalidResponseException;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Bramato\LaravelAi\Facades\LaravelAi;
use Illuminate\Support\Facades\Http;

// Note: uses(TestCase::class) is applied globally via tests/Pest.php

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('laravel-ai.default', 'openai');
    config()->set('laravel-ai.providers.openai.api_key', 'test-openai-key');
    config()->set('laravel-ai.providers.openai.model', 'gpt-test');
    config()->set('laravel-ai.providers.openai.options.base_uri', 'https://api.openai.com/v1'); // Explicitly set for clarity
});

it('can get successful response via interface', function () {
    // Arrange: Fake the HTTP response
    // Uses global helper: getFakeSuccessResponseData()
    $fakeResponseData = getFakeSuccessResponseData(model: 'gpt-test', content: '\n\nHello there!');
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200),
    ]);

    // Act: Make the request through the interface
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Hello']);
    $response = $client->chat($request);

    // Assert: Check the response DTO
    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->id)->toBe($fakeResponseData['id'])
        ->and($response->model)->toBe('gpt-test')
        ->and($response->content)->toBe('\n\nHello there!')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->usage['total_tokens'])->toBe(30) // Matches helper default
        ->and($response->isJson)->toBeFalse()
        ->and($response->decodedJsonContent)->toBeNull()
        ->and($response->rawResponse)->toBe($fakeResponseData);

    // Assert HTTP request was made
    Http::assertSentCount(1);
    Http::assertSent(function ($httpRequest) {
        return $httpRequest->url() === 'https://api.openai.com/v1/chat/completions' &&
            $httpRequest->method() === 'POST' &&
            $httpRequest->hasHeader('Authorization', 'Bearer test-openai-key') &&
            isset($httpRequest->data()['model']) && $httpRequest->data()['model'] === 'gpt-test' &&
            isset($httpRequest->data()['messages'][0]['role']) && $httpRequest->data()['messages'][0]['role'] === 'user';
    });
});

it('can get successful response via facade', function () {
    // Uses global helper: getFakeSuccessResponseData()
    $fakeResponseData = getFakeSuccessResponseData(id: 'chatcmpl-456', model: 'gpt-test', content: 'Facade response');
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200),
    ]);

    $request = new ChatRequest(['prompt' => 'Hello Facade']);
    $response = LaravelAi::chat($request); // Use the Facade

    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->content)->toBe('Facade response')
        ->and($response->id)->toBe('chatcmpl-456');

    Http::assertSentCount(1);
});

it('throws AuthenticationException on 401 error', function () {
    // Arrange: Fake a 401 response
    // Uses global helper: getFakeErrorResponseData()
    $errorResponse = getFakeErrorResponseData('Incorrect API key provided', 'invalid_request_error', 'invalid_api_key');
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($errorResponse, 401),
    ]);

    // Act & Assert: Expect the exception
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Auth Error']);

    // Expect the specific message from OpenAiClient handler
    expect(fn() => $client->chat($request))->toThrow(AuthenticationException::class, 'Incorrect API key provided');
});

it('throws LlmApiException on 500 error', function () {
    // Arrange: Fake a 500 response
    // Uses global helper: getFakeErrorResponseData()
    $errorResponse = getFakeErrorResponseData('The server had an error', 'server_error', 'internal_server_error');
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($errorResponse, 500),
    ]);

    // Act & Assert: Expect the exception
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Server Error']);

    expect(fn() => $client->chat($request))->toThrow(LlmApiException::class, 'OpenAI API Error - Internal Server Error (internal_server_error): The server had an error');
});

it('throws InvalidResponseException on malformed success response', function () {
    // Arrange: Fake a 200 response with missing required fields (e.g., choices)
    $malformedResponseData = [
        'id' => 'chatcmpl-789',
        'object' => 'chat.completion',
        'created' => time(),
        'model' => 'gpt-test',
        // 'choices' is missing
        'usage' => [ /* ... */],
    ];

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($malformedResponseData, 200),
    ]);

    // Act & Assert: Expect the exception
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Malformed']);

    expect(fn() => $client->chat($request))->toThrow(InvalidResponseException::class, 'Invalid response structure received from OpenAI API');
});

it('sends organization header when configured', function () {
    // Configure organization ID
    config()->set('laravel-ai.providers.openai.options.organization', 'org-12345');

    $fakeResponseData = getFakeSuccessResponseData();
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200),
    ]);

    // Re-resolve client to pick up new config
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Organization']);
    $client->chat($request);

    Http::assertSent(function ($httpRequest) {
        return $httpRequest->hasHeader('OpenAI-Organization', 'org-12345');
    });
});
