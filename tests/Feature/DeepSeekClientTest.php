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

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('laravel-ai.default', 'deepseek');
    config()->set('laravel-ai.providers.deepseek.api_key', 'test-deepseek-key');
    config()->set('laravel-ai.providers.deepseek.model', 'deepseek-test');
    // Test with the default base_uri (ends in /v1)
    config()->set('laravel-ai.providers.deepseek.options', []); // Reset options
});

it('can get successful response via interface', function () {
    // Uses global helper: getFakeSuccessResponseData()
    $fakeResponseData = getFakeSuccessResponseData('ds-123', 'deepseek-test', 'DeepSeek says hi');
    Http::fake([
        'api.deepseek.com/v1/chat/completions' => Http::response($fakeResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Hello DeepSeek']);
    $response = $client->chat($request);

    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->content)->toBe('DeepSeek says hi')
        ->and($response->id)->toBe('ds-123')
        ->and($response->model)->toBe('deepseek-test')
        ->and($response->rawResponse)->toBe($fakeResponseData);

    Http::assertSentCount(1);
    Http::assertSent(function ($httpRequest) {
        return $httpRequest->url() === 'https://api.deepseek.com/v1/chat/completions' && // Check the full URL
            $httpRequest->method() === 'POST' &&
            $httpRequest->hasHeader('Authorization', 'Bearer test-deepseek-key') &&
            isset($httpRequest->data()['model']) && $httpRequest->data()['model'] === 'deepseek-test';
    });
});

it('can get successful response with custom base_uri (no /v1)', function () {
    // Override base_uri for this test
    config()->set('laravel-ai.providers.deepseek.options.base_uri', 'https://custom.deepseek.endpoint');

    // Uses global helper: getFakeSuccessResponseData()
    $fakeResponseData = getFakeSuccessResponseData('ds-456', 'deepseek-test', 'DeepSeek custom endpoint');
    Http::fake([
        'custom.deepseek.endpoint/v1/chat/completions' => Http::response($fakeResponseData, 200), // Expect client to add /v1
    ]);

    $client = app(LlmClientInterface::class); // Re-resolve client after changing config
    $request = new ChatRequest(['prompt' => 'Hello Custom DeepSeek']);
    $response = $client->chat($request);

    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->content)->toBe('DeepSeek custom endpoint')
        ->and($response->id)->toBe('ds-456');

    Http::assertSentCount(1);
    Http::assertSent(function ($httpRequest) {
        // Client should construct the correct URL
        return $httpRequest->url() === 'https://custom.deepseek.endpoint/v1/chat/completions' &&
            $httpRequest->hasHeader('Authorization', 'Bearer test-deepseek-key');
    });
});

it('throws AuthenticationException on 401 error', function () {
    // Uses global helper: getFakeErrorResponseData()
    $errorResponse = getFakeErrorResponseData('Invalid API key', 'auth_error', 'invalid_key');
    Http::fake([
        'api.deepseek.com/v1/chat/completions' => Http::response($errorResponse, 401),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test DeepSeek Auth']);

    // Expect the specific message from DeepSeekClient handler
    expect(fn () => $client->chat($request))->toThrow(AuthenticationException::class, 'Invalid API key');
});

it('throws LlmApiException on 500 error', function () {
    // Uses global helper: getFakeErrorResponseData()
    $errorResponse = getFakeErrorResponseData('Server busy', 'server_error', 'busy');
    Http::fake([
        'api.deepseek.com/v1/chat/completions' => Http::response($errorResponse, 500),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test DeepSeek Server Error']);

    expect(fn () => $client->chat($request))->toThrow(LlmApiException::class, 'DeepSeek API Error - Internal Server Error (busy): Server busy');
});

it('throws InvalidResponseException on malformed success response', function () {
    $malformedResponseData = [
        'id' => 'ds-malformed',
        // 'choices' missing
    ];
    Http::fake([
        'api.deepseek.com/v1/chat/completions' => Http::response($malformedResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test DeepSeek Malformed']);

    expect(fn () => $client->chat($request))->toThrow(InvalidResponseException::class, 'Invalid response structure received from DeepSeek API.');
});
