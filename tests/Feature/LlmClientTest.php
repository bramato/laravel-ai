<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Exceptions\AuthenticationException;
use Bramato\LaravelAi\Exceptions\InvalidResponseException;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Bramato\LaravelAi\Facades\LaravelAi; // Use the Facade as well
use Bramato\LaravelAi\Tests\TestCase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

// --- Helper function to set up common fake data ---
function getFakeSuccessResponseData(string $id = 'chatcmpl-123', string $model = 'gpt-test', string $content = '\n\nHello there!'): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion',
        'created' => 1677652288,
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 9,
            'completion_tokens' => 12,
            'total_tokens' => 21,
        ],
    ];
}

function getFakeErrorResponseData(string $message, string $type, string $code): array
{
    return [
        'error' => [
            'message' => $message,
            'type' => $type,
            'param' => null,
            'code' => $code,
        ],
    ];
}

// --- OpenAI Tests ---
group('openai', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        config()->set('laravel-ai.default', 'openai');
        config()->set('laravel-ai.providers.openai.api_key', 'test-openai-key');
        config()->set('laravel-ai.providers.openai.model', 'gpt-test');
        config()->set('laravel-ai.providers.openai.options.base_uri', 'https://api.openai.com/v1'); // Explicitly set for clarity
    });

    it('can get successful response via interface', function () {
        // Arrange: Fake the HTTP response
        $fakeResponseData = getFakeSuccessResponseData();
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($fakeResponseData, 200),
        ]);

        // Act: Make the request through the interface
        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Hello']);
        $response = $client->chat($request);

        // Assert: Check the response DTO
        expect($response)->toBeInstanceOf(ChatResponse::class)
            ->and($response->id)->toBe('chatcmpl-123')
            ->and($response->model)->toBe('gpt-test')
            ->and($response->content)->toBe('\n\nHello there!')
            ->and($response->finishReason)->toBe('stop')
            ->and($response->usage['total_tokens'])->toBe(21)
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
        $fakeResponseData = getFakeSuccessResponseData('chatcmpl-456', 'gpt-test', 'Facade response');
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
        $errorResponse = getFakeErrorResponseData('Incorrect API key provided', 'invalid_request_error', 'invalid_api_key');
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($errorResponse, 401),
        ]);

        // Act & Assert: Expect the exception
        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Auth Error']);

        expect(fn() => $client->chat($request))->toThrow(AuthenticationException::class, 'OpenAI API Error (invalid_api_key): Incorrect API key provided');
    });

    it('throws LlmApiException on 500 error', function () {
        // Arrange: Fake a 500 response
        $errorResponse = getFakeErrorResponseData('The server had an error', 'server_error', 'internal_server_error');
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($errorResponse, 500),
        ]);

        // Act & Assert: Expect the exception
        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Server Error']);

        expect(fn() => $client->chat($request))->toThrow(LlmApiException::class, 'OpenAI API Error (internal_server_error, status:500): The server had an error');
    });

    it('throws InvalidResponseException on malformed success response', function () {
        // Arrange: Fake a 200 response with missing required fields (e.g., choices)
        $malformedResponseData = [
            'id' => 'chatcmpl-789',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'gpt-test',
            // 'choices' is missing
            'usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 12,
                'total_tokens' => 21,
            ],
        ];

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($malformedResponseData, 200),
        ]);

        // Act & Assert: Expect the exception
        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Malformed']);

        expect(fn() => $client->chat($request))->toThrow(InvalidResponseException::class, 'Invalid response structure received from OpenAI API.');
    });
});

// --- DeepSeek Tests ---
group('deepseek', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        config()->set('laravel-ai.default', 'deepseek');
        config()->set('laravel-ai.providers.deepseek.api_key', 'test-deepseek-key');
        config()->set('laravel-ai.providers.deepseek.model', 'deepseek-test');
        // Test with the default base_uri (ends in /v1)
        config()->set('laravel-ai.providers.deepseek.options', []); // Reset options
    });

    it('can get successful response via interface', function () {
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
        $errorResponse = getFakeErrorResponseData('Invalid API key', 'auth_error', 'invalid_key');
        Http::fake([
            'api.deepseek.com/v1/chat/completions' => Http::response($errorResponse, 401),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test DeepSeek Auth']);

        expect(fn() => $client->chat($request))->toThrow(AuthenticationException::class, 'DeepSeek Authentication failed'); // Match error message in client
    });

    it('throws LlmApiException on 500 error', function () {
        $errorResponse = getFakeErrorResponseData('Server busy', 'server_error', 'busy');
        Http::fake([
            'api.deepseek.com/v1/chat/completions' => Http::response($errorResponse, 500),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test DeepSeek Server Error']);

        expect(fn() => $client->chat($request))->toThrow(LlmApiException::class, 'DeepSeek API Error (busy, status:500): Server busy');
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

        expect(fn() => $client->chat($request))->toThrow(InvalidResponseException::class, 'Invalid response structure received from DeepSeek API.');
    });
});

// --- Gemini Tests ---
group('gemini', function () {
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

    // Helper function for Gemini fake success response
    function getFakeGeminiSuccessResponse(string $content = 'Gemini says hello', string $finishReason = 'STOP', ?array $usage = null): array
    {
        $response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => $content]],
                        'role' => 'model',
                    ],
                    'finishReason' => $finishReason,
                    'index' => 0,
                    'safetyRatings' => [/* ... */],
                ],
            ],
        ];
        if ($usage) {
            $response['usageMetadata'] = $usage;
        }
        return $response;
    }

    // Helper function for Gemini fake error response
    function getFakeGeminiErrorResponse(int $code, string $message, string $status): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ];
    }

    it('can get successful response via interface', function () use ($fullApiUrlPattern, $model) {
        $fakeUsage = ['promptTokenCount' => 10, 'candidatesTokenCount' => 20, 'totalTokenCount' => 30];
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
        $errorResponse = getFakeGeminiErrorResponse(403, 'API key not valid. Please pass a valid API key.', 'PERMISSION_DENIED');
        Http::fake([
            $fullApiUrlPattern => Http::response($errorResponse, 403),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Gemini Auth']);

        expect(fn() => $client->chat($request))
            ->toThrow(AuthenticationException::class, 'API key not valid. Please pass a valid API key.');
    });

    it('throws LlmApiException on 400 error (e.g., invalid argument)', function () use ($fullApiUrlPattern) {
        $errorResponse = getFakeGeminiErrorResponse(400, 'Invalid model specified', 'INVALID_ARGUMENT');
        Http::fake([
            $fullApiUrlPattern => Http::response($errorResponse, 400),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Gemini Bad Request']);

        expect(fn() => $client->chat($request))
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

        expect(fn() => $client->chat($request))
            ->toThrow(InvalidResponseException::class, 'Gemini request blocked due to: SAFETY');
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

        expect(fn() => $client->chat($request))
            ->toThrow(InvalidResponseException::class, 'Invalid or incomplete response structure received from Gemini API.');
    });

    it('can get successful JSON response in JSON mode', function () use ($fullApiUrlPattern, $model) {
        $jsonContent = json_encode(['message' => 'Gemini JSON response', 'status' => 'ok']);
        $fakeResponseData = getFakeGeminiSuccessResponse($jsonContent);
        Http::fake([
            $fullApiUrlPattern => Http::response($fakeResponseData, 200),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest([
            'prompt' => 'Respond with JSON',
            'jsonMode' => true,
        ]);
        $response = $client->chat($request);

        expect($response)->toBeInstanceOf(ChatResponse::class)
            ->and($response->content)->toBe($jsonContent) // Content remains raw JSON string
            ->and($response->isJson)->toBeTrue()
            ->and($response->decodedJsonContent)->toBe(['message' => 'Gemini JSON response', 'status' => 'ok']);

        Http::assertSent(function ($httpRequest) use ($fullApiUrlPattern) {
            // Check that generationConfig has response_mime_type
            return $httpRequest->url() === $fullApiUrlPattern &&
                isset($httpRequest->data()['generationConfig']['response_mime_type']) &&
                $httpRequest->data()['generationConfig']['response_mime_type'] === 'application/json';
        });
    });

    it('throws exception if JSON mode requested but api version is not v1beta', function () {
        // Override API version for this test
        config()->set('laravel-ai.providers.gemini.options.version', 'v1');

        $client = app(LlmClientInterface::class); // Re-resolve client
        $request = new ChatRequest([
            'prompt' => 'Respond with JSON',
            'jsonMode' => true,
        ]);

        expect(fn() => $client->chat($request))
            ->toThrow(LlmApiException::class, 'Gemini JSON mode requires the \'v1beta\' API version');
    });
});

// --- Claude Tests ---
group('claude', function () {
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

    // Helper function for Claude fake success response
    function getFakeClaudeSuccessResponse(
        string $id = 'msg_123',
        string $content = 'Claude responds.',
        string $stopReason = 'end_turn',
        ?array $usage = ['input_tokens' => 10, 'output_tokens' => 20]
    ): array {
        return [
            'id' => $id,
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test', // Or the specific model used
            'content' => [
                ['type' => 'text', 'text' => $content],
            ],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => $usage,
        ];
    }

    // Helper function for Claude fake error response
    function getFakeClaudeErrorResponse(string $type, string $message): array
    {
        return [
            'type' => 'error',
            'error' => [
                'type' => $type,
                'message' => $message,
            ],
        ];
    }

    it('can get successful response via interface', function () use ($endpoint, $model, $apiKey, $apiVersion) {
        $fakeResponseData = getFakeClaudeSuccessResponse();
        Http::fake([
            $endpoint => Http::response($fakeResponseData, 200),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Hello Claude']);
        $response = $client->chat($request);

        expect($response)->toBeInstanceOf(ChatResponse::class)
            ->and($response->content)->toBe('Claude responds.')
            ->and($response->model)->toBe($model)
            ->and($response->id)->toBe('msg_123')
            ->and($response->finishReason)->toBe('end_turn')
            ->and($response->usage['input_tokens'])->toBe(10)
            ->and($response->usage['output_tokens'])->toBe(20)
            ->and($response->isJson)->toBeFalse()
            ->and($response->rawResponse)->toBe($fakeResponseData);

        Http::assertSentCount(1);
        Http::assertSent(function ($httpRequest) use ($endpoint, $apiKey, $apiVersion) {
            return $httpRequest->url() === $endpoint &&
                $httpRequest->method() === 'POST' &&
                $httpRequest->hasHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $apiVersion,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]) &&
                isset($httpRequest->data()['model']) && $httpRequest->data()['model'] === 'claude-test' &&
                isset($httpRequest->data()['max_tokens']) && is_int($httpRequest->data()['max_tokens']) &&
                isset($httpRequest->data()['messages'][0]['role']) && $httpRequest->data()['messages'][0]['role'] === 'user';
        });
    });

    it('can get successful response with system prompt', function () use ($endpoint, $model) {
        $fakeResponseData = getFakeClaudeSuccessResponse(content: 'Understood system prompt.');
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
        $errorResponse = getFakeClaudeErrorResponse('authentication_error', 'Invalid API Key');
        Http::fake([
            $endpoint => Http::response($errorResponse, 401),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Claude Auth 401']);

        expect(fn() => $client->chat($request))
            ->toThrow(AuthenticationException::class, 'Claude Authentication failed - Invalid API Key');
    });

    it('throws AuthenticationException on 403 error', function () use ($endpoint) {
        $errorResponse = getFakeClaudeErrorResponse('permission_error', 'You do not have permission to use this model');
        Http::fake([
            $endpoint => Http::response($errorResponse, 403),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Claude Auth 403']);

        expect(fn() => $client->chat($request))
            ->toThrow(AuthenticationException::class, 'You do not have permission to use this model');
    });

    it('throws LlmApiException on 400 error (e.g., invalid request)', function () use ($endpoint) {
        $errorResponse = getFakeClaudeErrorResponse('invalid_request_error', 'max_tokens must be positive');
        Http::fake([
            $endpoint => Http::response($errorResponse, 400),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Claude Bad Request', 'options' => ['max_tokens' => -1]]);

        expect(fn() => $client->chat($request))
            ->toThrow(LlmApiException::class, 'Claude API Error - Bad Request (invalid_request_error): max_tokens must be positive');
    });

    it('throws LlmApiException on invalid message sequence (consecutive user messages)', function () {
        $client = app(LlmClientInterface::class);
        $request = new ChatRequest([
            'prompt' => 'Third user message',
            'history' => [
                ['role' => 'user', 'content' => 'First user message'],
                ['role' => 'assistant', 'content' => 'Model response'],
                ['role' => 'user', 'content' => 'Second user message'], // Invalid history
            ],
        ]);

        expect(fn() => $client->chat($request))
            ->toThrow(LlmApiException::class, 'Invalid message sequence for Claude: Last message in history cannot be from \'user\'.');
    });

    it('throws InvalidResponseException on malformed success response', function () use ($endpoint) {
        $malformedResponseData = [
            'id' => 'msg_malformed',
            'type' => 'message',
            // Missing 'content' or other required fields
        ];
        Http::fake([
            $endpoint => Http::response($malformedResponseData, 200),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest(['prompt' => 'Test Claude Malformed']);

        expect(fn() => $client->chat($request))
            ->toThrow(InvalidResponseException::class, 'Invalid response structure received from Claude API.');
    });

    it('handles JSON mode flag correctly (though relies on prompt)', function () use ($endpoint) {
        $jsonContent = json_encode(['data' => 'some json']);
        $fakeResponseData = getFakeClaudeSuccessResponse(content: $jsonContent);
        Http::fake([
            $endpoint => Http::response($fakeResponseData, 200),
        ]);

        $client = app(LlmClientInterface::class);
        $request = new ChatRequest([
            'prompt' => 'Please respond ONLY with JSON: {\"data\": \"some json\"}',
            'jsonMode' => true,
        ]);
        $response = $client->chat($request);

        expect($response->isJson)->toBeTrue()
            ->and($response->decodedJsonContent)->toBe(['data' => 'some json'])
            ->and($response->content)->toBe($jsonContent);

        // No specific API parameter to check for JSON mode, unlike OpenAI/Gemini
        Http::assertSentCount(1);
    });
});

// TODO: Add cross-client tests for specific features (e.g., JSON mode consistency)
