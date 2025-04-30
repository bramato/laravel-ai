<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Illuminate\Support\Facades\Http;

// Note: uses(TestCase::class) is applied globally via tests/Pest.php
// Global helper functions like getFakeSuccessResponseData() are available from tests/Pest.php

beforeEach(function () {
    Http::preventStrayRequests();
    // Reset config to avoid test pollution
    config(['laravel-ai' => require __DIR__ . '/../../config/laravel-ai.php']);
});

// OpenAI JSON Mode Test
it('handles OpenAI JSON mode correctly', function () {
    config()->set('laravel-ai.default', 'openai');
    config()->set('laravel-ai.providers.openai.api_key', 'test-openai-key');
    config()->set('laravel-ai.providers.openai.model', 'gpt-json-test'); // Use a model known to support JSON mode
    config()->set('laravel-ai.providers.openai.options.base_uri', 'https://api.openai.com/v1');

    $expectedJsonResponse = ['key' => 'value', 'nested' => ['num' => 1]];
    // Uses global helper from tests/Pest.php
    $fakeResponseData = getFakeSuccessResponseData(
        id: 'chatcmpl-json-1',
        model: 'gpt-json-test',
        content: json_encode($expectedJsonResponse) // Raw response content is the JSON string
    );
    Http::fake(['api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200)]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Generate JSON data',
        'jsonMode' => true,
    ]);
    $response = $client->chat($request);

    expect($response->isJson)->toBeTrue()
        ->and($response->content)->toBe(json_encode($expectedJsonResponse))
        ->and($response->decodedJsonContent)->toEqual($expectedJsonResponse); // Use toEqual for array/object comparison

    // Assert request payload includes JSON mode parameter
    Http::assertSent(function ($httpRequest) {
        return isset($httpRequest->data()['response_format'])
            && $httpRequest->data()['response_format'] === ['type' => 'json_object'];
    });
});

// DeepSeek JSON Mode Test (Assuming OpenAI compatibility)
it('handles DeepSeek JSON mode correctly', function () {
    config()->set('laravel-ai.default', 'deepseek');
    config()->set('laravel-ai.providers.deepseek.api_key', 'test-deepseek-key');
    config()->set('laravel-ai.providers.deepseek.model', 'deepseek-json-test');
    // Assuming default base_uri is fine (https://api.deepseek.com/v1)
    config()->set('laravel-ai.providers.deepseek.options', []);

    $expectedJsonResponse = ['status' => 'ok', 'data' => [1, 2, 3]];
    // Uses global helper from tests/Pest.php
    $fakeResponseData = getFakeSuccessResponseData(
        id: 'ds-json-1',
        model: 'deepseek-json-test',
        content: json_encode($expectedJsonResponse)
    );
    Http::fake(['api.deepseek.com/v1/chat/completions' => Http::response($fakeResponseData, 200)]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Output JSON status',
        'jsonMode' => true,
    ]);
    $response = $client->chat($request);

    expect($response->isJson)->toBeTrue()
        ->and($response->decodedJsonContent)->toEqual($expectedJsonResponse);

    Http::assertSent(function ($httpRequest) {
        return isset($httpRequest->data()['response_format'])
            && $httpRequest->data()['response_format'] === ['type' => 'json_object'];
    });
});

// Gemini JSON Mode Test (Requires v1beta)
it('handles Gemini JSON mode correctly', function () {
    $apiVersion = 'v1beta';
    $model = 'gemini-pro-json';
    $apiKey = 'test-gemini-key-json';
    $baseUri = 'https://generativelanguage.googleapis.com';
    $geminiFullApiUrlPattern = "{$baseUri}/{$apiVersion}/models/{$model}:generateContent?key={$apiKey}";

    config()->set('laravel-ai.default', 'gemini');
    config()->set('laravel-ai.providers.gemini.api_key', $apiKey);
    config()->set('laravel-ai.providers.gemini.model', $model);
    config()->set('laravel-ai.providers.gemini.options', ['version' => $apiVersion]); // Ensure v1beta

    $expectedJsonResponse = ['message' => 'Success', 'code' => 200];
    // Gemini returns JSON directly in the text part
    // Uses global helper from tests/Pest.php
    $fakeResponseData = getFakeGeminiSuccessResponse(
        content: json_encode($expectedJsonResponse),
        finishReason: 'STOP' // Needs finishReason
    );
    Http::fake([$geminiFullApiUrlPattern => Http::response($fakeResponseData, 200)]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Respond with JSON object',
        'jsonMode' => true,
    ]);
    $response = $client->chat($request);

    expect($response->isJson)->toBeTrue()
        ->and($response->decodedJsonContent)->toEqual($expectedJsonResponse)
        ->and($response->content)->toBe(json_encode($expectedJsonResponse)); // Content is still the raw JSON string

    // Assert request payload includes JSON mime type in generationConfig
    Http::assertSent(function ($httpRequest) use ($geminiFullApiUrlPattern) {
        return $httpRequest->url() === $geminiFullApiUrlPattern
            && isset($httpRequest->data()['generationConfig']['response_mime_type'])
            && $httpRequest->data()['generationConfig']['response_mime_type'] === 'application/json';
    });
});

it('throws exception if Gemini JSON mode requested but api version is not v1beta', function () {
    config()->set('laravel-ai.default', 'gemini');
    config()->set('laravel-ai.providers.gemini.api_key', 'test-gemini-key');
    config()->set('laravel-ai.providers.gemini.model', 'gemini-pro');
    config()->set('laravel-ai.providers.gemini.options', ['version' => 'v1']); // Set non-beta version

    $client = app(LlmClientInterface::class); // Re-resolve client
    $request = new ChatRequest([
        'prompt' => 'Respond with JSON',
        'jsonMode' => true,
    ]);

    expect(fn() => $client->chat($request))
        ->toThrow(LlmApiException::class, 'Gemini JSON mode requires the \'v1beta\' API version');
});

// Claude JSON Mode Test (Relies on prompt engineering)
it('handles Claude JSON mode via prompt and correctly decodes', function () {
    $apiVersion = '2023-06-01';
    $model = 'claude-json-test';
    $apiKey = 'test-claude-key-json';
    $baseUri = 'https://api.anthropic.com/v1';
    $claudeEndpoint = "{$baseUri}/messages";

    config()->set('laravel-ai.default', 'claude');
    config()->set('laravel-ai.providers.claude.api_key', $apiKey);
    config()->set('laravel-ai.providers.claude.model', $model);
    config()->set('laravel-ai.providers.claude.options', [
        'version' => $apiVersion,
        'base_uri' => $baseUri,
    ]);

    $expectedJsonResponse = ['user_id' => 123, 'name' => 'Claude'];
    // Claude needs explicit instruction, often including ```json ``` markers
    // The client should extract the JSON even with surrounding text.
    $fakeResponseContent = "Okay, here is the JSON data:\n```json\n" . json_encode($expectedJsonResponse, JSON_PRETTY_PRINT) . "\n```\nSome trailing text.";
    // Uses global helper from tests/Pest.php
    $fakeResponseData = getFakeClaudeSuccessResponse(
        id: 'msg-claude-json',
        content: $fakeResponseContent
    );
    $fakeResponseData['model'] = $model; // Ensure model matches for assertion
    Http::fake([$claudeEndpoint => Http::response($fakeResponseData, 200)]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Return user data as a JSON object ONLY.', // Prompt engineering part
        'jsonMode' => true, // Tells our client to *try* decoding
    ]);
    $response = $client->chat($request);

    expect($response->isJson)->toBeTrue()
        // Content is the raw response including the surrounding text and backticks
        ->and($response->content)->toBe($fakeResponseContent)
        // Decoded content should be the pure JSON object
        ->and($response->decodedJsonContent)->toEqual($expectedJsonResponse);

    // No specific API parameter for Claude JSON mode to check in the request
    Http::assertSent(function ($httpRequest) {
        // Check that the prompt was sent correctly
        return isset($httpRequest->data()['messages'][0]['content'])
            && str_contains($httpRequest->data()['messages'][0]['content'], 'JSON object ONLY');
    });
});

// Test JSON decoding failure
it('sets isJson to false and decodedJsonContent to null when jsonMode is true but content is not valid JSON', function () {
    config()->set('laravel-ai.default', 'openai'); // Use any provider supporting jsonMode flag
    config()->set('laravel-ai.providers.openai.api_key', 'test-key');
    config()->set('laravel-ai.providers.openai.model', 'test-model');
    config()->set('laravel-ai.providers.openai.options.base_uri', 'https://api.openai.com/v1');

    $notJsonContent = "This is just plain text, not JSON.";
    // Uses global helper from tests/Pest.php
    $fakeResponseData = getFakeSuccessResponseData(content: $notJsonContent);
    Http::fake(['api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200)]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest([
        'prompt' => 'Generate something',
        'jsonMode' => true, // Request JSON decode
    ]);
    $response = $client->chat($request);

    expect($response->isJson)->toBeFalse()
        ->and($response->content)->toBe($notJsonContent)
        ->and($response->decodedJsonContent)->toBeNull();

    // Assert the request parameter was still sent (for OpenAI/DeepSeek)
    Http::assertSent(function ($httpRequest) {
        return isset($httpRequest->data()['response_format'])
            && $httpRequest->data()['response_format'] === ['type' => 'json_object'];
    });
});
