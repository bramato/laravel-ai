<?php

namespace Bramato\LaravelAi\Clients;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Exceptions\AuthenticationException;
use Bramato\LaravelAi\Exceptions\InvalidResponseException;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

class ClaudeClient implements LlmClientInterface
{
    protected PendingRequest $httpClient;
    protected string $apiVersion;
    protected const DEFAULT_MAX_TOKENS = 1024;

    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        $this->apiVersion = $this->options['version'] ?? '2023-06-01'; // Required by Claude
        $this->httpClient = $this->configureHttpClient();
    }

    /**
     * Configures the HTTP client with base URI and required Claude headers.
     *
     * @throws AuthenticationException
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.anthropic.com/v1';
        $timeout = $this->options['timeout'] ?? 60;

        if (empty($this->apiKey)) {
            throw new AuthenticationException('Claude API Key is missing. Please configure it in laravel-ai.php or .env.');
        }
        if (empty($this->apiVersion)) {
            // Should not happen with default, but good practice
            throw new InvalidArgumentException('Claude API Version (anthropic-version header) is missing. Please configure it in laravel-ai.php options.');
        }

        return $this->httpFactory->baseUrl($baseUri)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
            ])
            ->acceptJson()
            ->contentTypeJson() // Claude expects application/json
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Claude API.
     *
     * @throws AuthenticationException
     * @throws InvalidResponseException
     * @throws LlmApiException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post('/messages', $payload);

            // Handle specific HTTP errors
            if ($response->status() === 401) {
                throw new AuthenticationException(
                    $response->json('error.message', 'Claude Authentication failed - Invalid API Key'),
                    $response->status()
                );
            }
            if ($response->status() === 403) {
                // Could be permissions or other issues
                throw new AuthenticationException(
                    $response->json('error.message', 'Claude Forbidden - Check Permissions or Request'),
                    $response->status()
                );
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response);
            }

            $responseData = $response->json();

            if (! $this->isValidResponseStructure($responseData)) {
                // Claude might return error details even with 200 OK sometimes
                if (isset($responseData['error']['type'])) {
                    $errorType = $responseData['error']['type'];
                    $errorMessage = $responseData['error']['message'] ?? 'Unknown error detail';
                    throw new InvalidResponseException("Invalid response structure, potential API error ({$errorType}): {$errorMessage}");
                }
                throw new InvalidResponseException('Invalid response structure received from Claude API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            throw new LlmApiException("HTTP Request Error calling Claude API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            throw new LlmApiException("An unexpected error occurred calling Claude API: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload for the Claude API request.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [],
            // 'max_tokens' is required by Claude API
            'max_tokens' => (int) ($request->options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];

        // Handle system prompt (if provided)
        if ($request->systemMessage) {
            // The 'system' parameter is the recommended way
            $payload['system'] = $request->systemMessage;
        }

        // Add history messages (user/assistant)
        // Ensure alternating roles, starting with user if system prompt wasn't used.
        $lastRole = null;
        if (empty($request->history) && !isset($payload['system'])) {
            // If no history and no system prompt, the main prompt is the first user message.
            // Handled below.
        } else {
            foreach ($request->history as $message) {
                if (isset($message['role'], $message['content']) && in_array($message['role'], ['user', 'assistant'])) {
                    // Ensure roles alternate (Claude strict requirement)
                    if ($lastRole !== null && $lastRole === $message['role']) {
                        // Option 1: Throw error
                        throw new LlmApiException("Invalid message sequence for Claude: Consecutive messages from role '{$message['role']}'. History must alternate roles.", 400);
                        // Option 2: Try to merge? Risky, could break context.
                        // Option 3: Insert dummy message? Also risky.
                        // Let's throw for now, user should provide correct history.
                    }
                    $payload['messages'][] = ['role' => $message['role'], 'content' => $message['content']];
                    $lastRole = $message['role'];
                }
            }
            // Validate role alternation with the upcoming main prompt
            if ($lastRole === 'user') {
                throw new LlmApiException('Invalid message sequence for Claude: Last message in history cannot be from \'user\'.', 400);
            }
        }

        // Add the main prompt (must be 'user' role)
        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        // Add other allowed options
        if (!empty($request->options)) {
            $allowedOptions = ['temperature', 'top_p', 'top_k', 'stop_sequences'];
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // Claude does not have a specific 'json_mode' parameter.
        // Achieving JSON output relies on prompt engineering.
        if ($request->jsonMode) {
            // Optionally, log a warning or modify prompt here if desired.
            // logger()->warning('Claude does not support a dedicated JSON mode parameter. Ensure your prompt requests JSON output.');
        }

        // Ensure max_tokens is always set and is an integer
        if (!isset($payload['max_tokens']) || !is_int($payload['max_tokens'])) {
            $payload['max_tokens'] = self::DEFAULT_MAX_TOKENS;
        }

        return $payload;
    }

    /**
     * Maps the successful Claude API response to the ChatResponse DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Claude response: `content` is an array, usually with one text block.
        $content = '';
        if (isset($responseData['content']) && is_array($responseData['content']) && isset($responseData['content'][0]['text'])) {
            // Concatenate text from all content blocks if there are multiple (though usually just one for non-streaming)
            foreach ($responseData['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        $decodedJson = null;
        if ($wasJsonModeRequested && !empty($content)) {
            // Attempt to decode if JSON mode was requested via prompt
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
            // else: $decodedJson remains null, $content holds the raw string
        }

        return new ChatResponse(
            $content,
            $responseData['stop_reason'] ?? 'unknown',
            $responseData['model'] ?? $this->model,
            $responseData['id'] ?? 'unknown', // Claude provides an ID
            $responseData['usage'] ?? null, // Claude provides usage {input_tokens, output_tokens}
            $wasJsonModeRequested && ($decodedJson !== null),
            $decodedJson,
            $responseData
        );
    }

    /**
     * Handles non-successful HTTP responses from Claude.
     *
     * @throws LlmApiException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error');

        $errorMessage = 'Unknown Claude API Error';
        $errorType = 'unknown_error'; // Claude uses `type` for errors

        if (is_array($errorData)) {
            $errorType = $errorData['type'] ?? $statusCode;
            $errorMessage = $errorData['message'] ?? $response->body();
        }

        match ($statusCode) {
            // 401/403 handled in chat()
            400 => throw new LlmApiException("Claude API Error - Bad Request ({$errorType}): {$errorMessage}", $statusCode),
            429 => throw new LlmApiException("Claude API Error - Rate Limit Exceeded ({$errorType}): {$errorMessage}", $statusCode),
            500 => throw new LlmApiException("Claude API Error - Internal Server Error ({$errorType}): {$errorMessage}", $statusCode),
            529 => throw new LlmApiException("Claude API Error - Overloaded ({$errorType}): {$errorMessage}", $statusCode), // Specific Claude overload error
            default => throw new LlmApiException("Claude API Error ({$errorType}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of the Claude response.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Check for error structure first
        if (isset($responseData['error']['type'])) {
            return false; // Let chat() method handle the error content
        }

        // Check for successful response structure
        return isset($responseData['id'], $responseData['model'], $responseData['content']) &&
            is_array($responseData['content']) &&
            count($responseData['content']) > 0 &&
            isset($responseData['content'][0]['type'], $responseData['content'][0]['text']) && // Check first block
            isset($responseData['stop_reason'], $responseData['usage']);
    }
}
