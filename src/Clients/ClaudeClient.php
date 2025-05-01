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

/**
 * Client implementation for interacting with Anthropic's Claude API.
 */
class ClaudeClient implements LlmClientInterface
{
    /**
     * The configured HTTP client instance.
     */
    protected PendingRequest $httpClient;

    /**
     * The configured Claude API version (e.g., '2023-06-01').
     */
    protected string $apiVersion;

    /**
     * Default value for max_tokens if not provided in options.
     * Claude API requires this parameter.
     */
    protected const DEFAULT_MAX_TOKENS = 1024;

    /**
     * @param  HttpClientFactory  $httpFactory  The Laravel HTTP client factory.
     * @param  string  $apiKey  The Claude API key.
     * @param  string  $model  The default Claude model ID to use for requests.
     * @param  array  $options  Additional configuration options (e.g., base_uri, timeout, version).
     *
     * @throws AuthenticationException If the API key is missing.
     * @throws InvalidArgumentException If the API version is missing.
     */
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
     * @return PendingRequest The configured HTTP client.
     *
     * @throws AuthenticationException If API key is missing.
     * @throws InvalidArgumentException If API version is missing.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.anthropic.com/v1';
        $timeout = $this->options['timeout'] ?? 60; // Increased default timeout

        if (empty($this->apiKey)) {
            throw new AuthenticationException('Claude API Key is missing. Please configure it in laravel-ai.php or .env.');
        }
        if (empty($this->apiVersion)) {
            // Should not happen with default, but good practice to check
            throw new InvalidArgumentException('Claude API Version (anthropic-version header) is required. Please configure providers.claude.options.version.');
        }

        return $this->httpFactory->baseUrl($baseUri)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Claude API's /messages endpoint.
     *
     * @param  ChatRequest  $request  The DTO containing the prompt, history, system prompt, and options.
     * @return ChatResponse The DTO containing the API response.
     *
     * @throws AuthenticationException If the API key is invalid or permissions are insufficient (401, 403).
     * @throws InvalidResponseException If the API response structure is invalid or indicates an error.
     * @throws LlmApiException For other API errors (rate limits, server errors, bad requests etc.).
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        try {
            // Build payload *inside* try-catch to catch message validation errors
            $payload = $this->buildPayload($request);
        } catch (Throwable $e) {
            // Rethrow specific exceptions, wrap others
            if ($e instanceof LlmApiException) {
                throw $e;
            }
            throw new LlmApiException("Failed to build Claude payload: {$e->getMessage()}", $e->getCode(), $e);
        }

        try {
            $response = $this->httpClient->post('/messages', $payload);

            // Handle specific HTTP errors first
            if ($response->status() === 401) {
                $errorMessage = $response->json('error.message', 'Claude Authentication failed - Invalid API Key');
                throw new AuthenticationException($errorMessage, $response->status());
            }
            if ($response->status() === 403) {
                // Could be permissions or other access issues
                $errorMessage = $response->json('error.message', 'Claude Forbidden - Check Permissions or Request Details');
                throw new AuthenticationException($errorMessage, $response->status());
            }

            // Handle other failed responses (4xx, 5xx)
            if ($response->failed()) {
                $this->handleErrorResponse($response);
            }

            $responseData = $response->json();

            // Validate the structure of the successful response
            if (! $this->isValidResponseStructure($responseData)) {
                // Check if it was an error structure returned with a 200 OK status
                if (isset($responseData['error']['type'])) {
                    $errorType = $responseData['error']['type'];
                    $errorMessage = $responseData['error']['message'] ?? 'Unknown error detail in 200 OK response';
                    // Classify this scenario as InvalidResponseException
                    throw new InvalidResponseException("Invalid response structure, received error details instead: ({$errorType}) {$errorMessage}");
                }
                // General invalid structure if no specific error found
                throw new InvalidResponseException('Invalid response structure received from Claude API (missing expected fields).');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            // Handles connection errors or other client-side request issues
            throw new LlmApiException("HTTP Request Error calling Claude API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own specific exceptions, wrap others for clarity
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            // Wrap unexpected errors during API interaction
            throw new LlmApiException("An unexpected error occurred during Claude API interaction: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload array for the Claude Messages API request.
     *
     * Handles mapping of system prompts, ensures message history alternates roles correctly,
     * includes the mandatory `max_tokens` parameter, and adds other supported options.
     *
     * @param  ChatRequest  $request  The request DTO.
     * @return array The payload ready for JSON encoding.
     *
     * @throws LlmApiException If the message sequence validation fails.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [],
            // 'max_tokens' is required by the Claude API.
            'max_tokens' => (int) ($request->options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];

        // Handle system prompt using the dedicated 'system' parameter.
        if ($request->systemMessage) {
            $payload['system'] = $request->systemMessage;
        }

        // --- Build Messages Array ---
        // Ensure messages strictly alternate between 'user' and 'assistant' roles.
        $lastRole = null;
        // Start role check based on whether a system prompt is present.
        // If system prompt exists, the first message *must* be 'user'.
        // If no system prompt, the first message *must* be 'user' (which will be the main prompt if history is empty).

        foreach ($request->history as $message) {
            if (isset($message['role'], $message['content']) && in_array($message['role'], ['user', 'assistant'])) {
                $currentRole = $message['role'];

                // Prevent consecutive messages from the same role.
                if ($lastRole !== null && $lastRole === $currentRole) {
                    throw new LlmApiException(
                        "Invalid message sequence for Claude: Consecutive messages found from role '{$currentRole}'. History must alternate between 'user' and 'assistant'.",
                        400 // Bad Request
                    );
                }
                // Ensure the very first message (if history is not empty) starts correctly relative to system prompt.
                if ($lastRole === null && isset($payload['system']) && $currentRole !== 'user') {
                    throw new LlmApiException(
                        "Invalid message sequence for Claude: First message after a system prompt must be from role 'user'.",
                        400
                    );
                }

                $payload['messages'][] = ['role' => $currentRole, 'content' => $message['content']];
                $lastRole = $currentRole;
            }
        }

        // Final validation: The last message *before* adding the main prompt must be 'assistant' (if history exists).
        if ($lastRole === 'user') {
            throw new LlmApiException(
                "Invalid message sequence for Claude: The last message in the history array must be from role 'assistant' before adding the final user prompt.",
                400
            );
        }

        // Add the main user prompt - this must always be the last message.
        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        // --- Add Other Options ---
        if (! empty($request->options)) {
            // Filter and add other supported Claude options.
            $allowedOptions = ['temperature', 'top_p', 'top_k', 'stop_sequences', 'stream']; // Added stream
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // --- JSON Mode Handling ---
        // Claude does not have a specific 'json_mode' API parameter.
        // The DTO flag `jsonMode` only controls whether the client *attempts* to parse the response.
        // Achieving JSON output relies entirely on prompt engineering.
        if ($request->jsonMode) {
            // Optionally, add a log warning if needed:
            // logger()->warning('Claude does not support a dedicated JSON mode parameter. Ensure your prompt explicitly requests JSON output.');
        }

        // Ensure max_tokens is always an integer (double-check after merging options).
        if (! isset($payload['max_tokens']) || ! is_int($payload['max_tokens'])) {
            $payload['max_tokens'] = self::DEFAULT_MAX_TOKENS;
        } elseif ($payload['max_tokens'] <= 0) {
            // Ensure max_tokens is positive, required by Claude
            throw new LlmApiException('Invalid option: max_tokens must be a positive integer for Claude.', 400);
        }

        return $payload;
    }

    /**
     * Maps the successful Claude API response data array to the ChatResponse DTO.
     *
     * @param  array  $responseData  The decoded JSON response data from the API.
     * @param  bool  $wasJsonModeRequested  Indicates if the original request asked for JSON.
     * @return ChatResponse The populated response DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Claude's response `content` is an array of blocks. For non-streaming, usually one text block.
        // We concatenate the text from all text blocks.
        $content = '';
        if (isset($responseData['content']) && is_array($responseData['content'])) {
            foreach ($responseData['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $content .= $block['text'];
                }
            }
        }

        $decodedJson = null;
        if ($wasJsonModeRequested && ! empty($content)) {
            // Tentativo di estrarre il blocco JSON delimitato da ```json ... ```
            if (preg_match('/```json\s*({.*?})\s*```/s', $content, $matches)) {
                $jsonString = $matches[1]; // Estrae il contenuto tra le parentesi graffe
                $decoded = json_decode($jsonString, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decodedJson = $decoded;
                }
            } else {
                // Fallback: Prova a decodificare l'intero contenuto se i delimitatori non sono presenti
                // Potrebbe funzionare se il modello restituisce solo JSON senza delimitatori
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decodedJson = $decoded;
                }
            }
        }

        // Passare un array associativo al costruttore di SimpleDTO
        return new ChatResponse([
            'content' => $content,
            'finishReason' => $responseData['stop_reason'] ?? 'unknown',
            'model' => $responseData['model'] ?? $this->model,
            'id' => $responseData['id'] ?? 'unknown',
            'usage' => $responseData['usage'] ?? null,
            'isJson' => $wasJsonModeRequested && ($decodedJson !== null),
            'decodedJsonContent' => $decodedJson,
            'rawResponse' => $responseData,
        ]);
    }

    /**
     * Handles non-successful (non-401/403) HTTP responses from Claude.
     *
     * @param  Response  $response  The failed HTTP response.
     *
     * @throws LlmApiException Mapped API error based on Claude error types.
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        // Claude typically wraps errors in an 'error' object with a 'type' and 'message'.
        $errorData = $response->json('error');

        $errorMessage = 'Unknown Claude API Error';
        $errorType = 'unknown_error_type'; // Claude uses `type` (e.g., 'invalid_request_error')

        if (is_array($errorData)) {
            $errorType = $errorData['type'] ?? $statusCode; // Use type if available, fallback to status
            $errorMessage = $errorData['message'] ?? $response->body();
        }

        // Map status codes and potentially error types to specific exceptions or messages.
        match ($statusCode) {
            // 401/403 are handled directly in chat() for AuthenticationException.
            400 => throw new LlmApiException("Claude API Error - Bad Request ({$errorType}): {$errorMessage}", $statusCode),
            429 => throw new LlmApiException("Claude API Error - Rate Limit Exceeded ({$errorType}): {$errorMessage}", $statusCode),
            500 => throw new LlmApiException("Claude API Error - Internal Server Error ({$errorType}): {$errorMessage}", $statusCode),
            529 => throw new LlmApiException("Claude API Error - Overloaded ({$errorType}): {$errorMessage}", $statusCode), // Claude specific overload error
            default => throw new LlmApiException("Claude API Error ({$errorType}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of a successful Claude response array.
     *
     * Checks for essential fields like id, model, content array, stop_reason, and usage.
     * Does not validate error structures here, as that implies failure.
     *
     * @param  array|null  $responseData  The decoded JSON data from the response.
     * @return bool True if the structure seems valid for a successful response, false otherwise.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Check specifically for the presence of an error object first.
        // If an error object exists, even with a 200 status, treat structure as invalid (for success path).
        if (isset($responseData['error']['type'])) {
            return false; // Let the chat() method handle responses containing error details.
        }

        // Check for the essential fields of a successful response.
        return isset($responseData['id'], $responseData['type']) && $responseData['type'] === 'message' &&
            isset($responseData['model'], $responseData['role']) && $responseData['role'] === 'assistant' &&
            isset($responseData['content']) && is_array($responseData['content']) && count($responseData['content']) > 0 &&
            isset($responseData['content'][0]['type'], $responseData['content'][0]['text']) && // Ensure first content block looks okay
            isset($responseData['stop_reason'], $responseData['usage']); // Check stop_reason and usage
    }
}
