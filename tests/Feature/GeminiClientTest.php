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

// Note: Using v1beta for testing as it supports more features like JSON mode
$apiVersion = 'v1beta';
$model = 'gemini-test';
$apiKey = 'test-gemini-key';
$baseUri = 'https://generativelanguage.googleapis.com';
$fullApiUrlPattern = "{$baseUri}/{$apiVersion}/models/{$model}:generateContent?key={$apiKey}";

beforeEach(function () use ($apiVersion, $model, $apiKey) {
    Http::preventStrayRequests();
    config()->set('laravel-ai.default', 'gemini');
    config()->set('laravel-ai.providers.gemini.api_key', $apiKey);
    config()->set('laravel-ai.providers.gemini.model', $model);
    config()->set('laravel-ai.providers.gemini.options', ['version' => $apiVersion]); // Ensure v1beta
});

it('can get successful response via interface', function () use ($fullApiUrlPattern, $model) {
    $fakeUsage = ['promptTokenCount' => 10, 'candidatesTokenCount' => 20, 'totalTokenCount' => 30];
    // Uses global helper: getFakeGeminiSuccessResponse
    $fakeResponseData = getFakeGeminiSuccessResponse('Hi from Gemini', 'STOP', $fakeUsage);
    Http::fake([
        $fullApiUrlPattern => Http::response($fakeResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Hello Gemini']);
    $response = $client->chat($request);

    expect($response)->toBeInstanceOf(ChatResponse::class)
        ->and($response->content)->toBe('Hi from Gemini')
        ->and($response->model)->toBe($model)
        ->and($response->id)->toStartWith('gemini-') // Check prefix
        ->and($response->finishReason)->toBe('STOP')
        ->and($response->usage['total_tokens'])->toBe(30)
        ->and($response->isJson)->toBeFalse()
        ->and($response->rawResponse)->toBe($fakeResponseData);

    Http::assertSentCount(1);
    Http::assertSent(function ($httpRequest) use ($fullApiUrlPattern) {
        // Check payload structure for Gemini
        return $httpRequest->url() === $fullApiUrlPattern &&
            $httpRequest->method() === 'POST' &&
            isset($httpRequest->data()['contents'][0]['role']) && $httpRequest->data()['contents'][0]['role'] === 'user' &&
            isset($httpRequest->data()['contents'][0]['parts'][0]['text']);
    });
});

it('throws AuthenticationException on 403 error', function () use ($fullApiUrlPattern) {
    // Uses global helper: getFakeGeminiErrorResponse
    $errorResponse = getFakeGeminiErrorResponse(403, 'API key not valid. Please pass a valid API key.', 'PERMISSION_DENIED');
    Http::fake([
        $fullApiUrlPattern => Http::response($errorResponse, 403),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Gemini Auth']);

    expect(fn () => $client->chat($request))
        ->toThrow(AuthenticationException::class, 'API key not valid. Please pass a valid API key.');
});

it('throws LlmApiException on 400 error (e.g., invalid argument)', function () use ($fullApiUrlPattern) {
    // Uses global helper: getFakeGeminiErrorResponse
    $errorResponse = getFakeGeminiErrorResponse(400, 'Invalid model specified', 'INVALID_ARGUMENT');
    Http::fake([
        $fullApiUrlPattern => Http::response($errorResponse, 400),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Gemini Bad Request']);

    expect(fn () => $client->chat($request))
        ->toThrow(LlmApiException::class, 'Gemini API Error - Bad Request (INVALID_ARGUMENT): Invalid model specified');
});

it('throws InvalidResponseException when response is blocked by safety settings', function () use ($fullApiUrlPattern) {
    $blockedResponse = [
        'promptFeedback' => [
            'blockReason' => 'SAFETY',
            'safetyRatings' => [
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'probability' => 'HIGH'],
            ],
        ],
    ];
    Http::fake([
        $fullApiUrlPattern => Http::response($blockedResponse, 200), // Sometimes blocked responses return 200
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Risky prompt']);

    expect(fn () => $client->chat($request))
        ->toThrow(InvalidResponseException::class, 'Gemini request blocked due to safety settings: SAFETY.');
});

it('throws InvalidResponseException on malformed success response (missing text)', function () use ($fullApiUrlPattern) {
    $malformedResponseData = [
        'candidates' => [
            [
                'content' => [
                    // 'parts' is missing text
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ],
        ],
    ];
    Http::fake([
        $fullApiUrlPattern => Http::response($malformedResponseData, 200),
    ]);

    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Gemini Malformed']);

    expect(fn () => $client->chat($request))
        ->toThrow(InvalidResponseException::class, 'Invalid or incomplete response structure received from Gemini API.');
});

// Note: JSON mode tests are handled in JsonModeTest.php
// Test for API version check is also covered there.

it('sends safety settings when configured', function () use ($fullApiUrlPattern) {
    $customSafetySettings = [
        [
            'category' => 'HARM_CATEGORY_HATE_SPEECH',
            'threshold' => 'BLOCK_ONLY_HIGH',
        ],
        [
            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        ],
    ];

    // Configure safety settings via options array
    config()->set('laravel-ai.providers.gemini.options.safety_settings', $customSafetySettings);

    $fakeResponseData = getFakeGeminiSuccessResponse('Safe content');
    Http::fake([
        $fullApiUrlPattern => Http::response($fakeResponseData, 200),
    ]);

    // Re-resolve client to pick up new config
    $client = app(LlmClientInterface::class);
    $request = new ChatRequest(['prompt' => 'Test Safety Settings']);
    $client->chat($request);

    Http::assertSent(function ($httpRequest) use ($customSafetySettings) {
        return isset($httpRequest->data()['safetySettings'])
            && $httpRequest->data()['safetySettings'] === $customSafetySettings;
    });
});
