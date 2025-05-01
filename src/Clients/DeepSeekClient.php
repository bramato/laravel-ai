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
use Throwable;

/**
 * Client implementation for interacting with DeepSeek's Chat API (often OpenAI-compatible).
 */
class DeepSeekClient implements LlmClientInterface
{
    /**
     * The configured HTTP client instance.
     */
    protected PendingRequest $httpClient;

    /**
     * The resolved API endpoint path (e.g., /chat/completions or /v1/chat/completions).
     */
    protected string $apiEndpoint;

    /**
     * @param  HttpClientFactory  $httpFactory  The Laravel HTTP client factory.
     * @param  string  $apiKey  The DeepSeek API key.
     * @param  string  $model  The default DeepSeek model ID to use for requests.
     * @param  array  $options  Additional configuration options (e.g., base_uri, timeout).
     */
    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        $this->httpClient = $this->configureHttpClient();
        // Determine the correct endpoint based on the base URI provided
        $this->apiEndpoint = $this->determineApiEndpoint();
    }

    /**
     * Configures the HTTP client with base URI and authentication.
     *
     * @return PendingRequest The configured HTTP client.
     */
    protected function configureHttpClient(): PendingRequest
    {
        // Default base URI for DeepSeek's OpenAI-compatible endpoint
        $baseUri = $this->options['base_uri'] ?? 'https://api.deepseek.com/v1';
        $timeout = $this->options['timeout'] ?? 30;

        return $this->httpFactory->baseUrl($baseUri)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    /**
     * Determines the correct relative API endpoint path based on the configured base URI.
     *
     * Handles cases where the base URI might or might not include the '/v1' path segment.
     *
     * @return string The API endpoint path (e.g., '/chat/completions' or '/v1/chat/completions').
     */
    protected function determineApiEndpoint(): string
    {
        $baseUri = rtrim($this->options['base_uri'] ?? 'https://api.deepseek.com/v1', '/');
        // Check if the effective baseUri already includes the version path
        if (str_ends_with($baseUri, '/v1')) {
            // Already configured with /v1, use relative path
            return '/chat/completions';
        } else {
            // Base URI is likely just the domain (e.g., https://api.deepseek.com),
            // assume the standard v1 path needs to be appended.
            return '/v1/chat/completions';
        }
    }

    /**
     * Sends a chat request to the DeepSeek API.
     *
     * @param  ChatRequest  $request  The DTO containing the prompt, history, and options.
     * @return ChatResponse The DTO containing the API response.
     *
     * @throws AuthenticationException If the API key is invalid (401).
     * @throws InvalidResponseException If the API response structure is invalid.
     * @throws LlmApiException For other API errors (rate limits, server errors, etc.).
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post($this->apiEndpoint, $payload);

            // Handle specific HTTP errors first
            if ($response->status() === 401) {
                $errorMessage = $response->json('error.message', 'DeepSeek Authentication failed - Invalid API Key');
                throw new AuthenticationException($errorMessage, $response->status());
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response); // Handles other 4xx/5xx
            }

            $responseData = $response->json();

            // Validate structure AFTER checking for errors
            if (! $this->isValidResponseStructure($responseData)) {
                // Check if it was an error response that somehow returned 200 OK
                if (isset($responseData['error']['message'])) {
                    $errorMessage = $responseData['error']['message'];
                    $errorCode = $responseData['error']['code'] ?? 'unknown_error_code';
                    throw new InvalidResponseException("Invalid response structure, received error details instead: ({$errorCode}) {$errorMessage}");
                }
                throw new InvalidResponseException('Invalid response structure received from DeepSeek API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            // Handles connection errors or other client-side request issues
            throw new LlmApiException("HTTP Request Error calling DeepSeek API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others for clarity
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            // Wrap unexpected errors
            throw new LlmApiException("An unexpected error occurred during DeepSeek API interaction: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload array for the DeepSeek Chat API request.
     * (Assumes OpenAI compatibility in structure).
     *
     * @param  ChatRequest  $request  The request DTO.
     * @return array The payload ready for JSON encoding.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [],
        ];

        // Add system message if provided
        if ($request->systemMessage) {
            $payload['messages'][] = ['role' => 'system', 'content' => $request->systemMessage];
        }

        // Add history messages
        foreach ($request->history as $message) {
            // DTO validation ensures role/content exist
            $payload['messages'][] = ['role' => $message['role'], 'content' => $message['content']];
        }

        // Add the main user prompt
        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        // Add allowed options from the request DTO
        if (! empty($request->options)) {
            // Assuming DeepSeek supports the same options as OpenAI. Verify with DeepSeek docs if needed.
            $allowedOptions = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed', 'stream'];
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // Handle JSON mode parameter
        if ($request->jsonMode) {
            // Assumes DeepSeek supports OpenAI-style JSON mode
            $payload['response_format'] = ['type' => 'json_object'];
            // Note: The user MUST ensure the prompt instructs the model to output JSON.
        }

        return $payload;
    }

    /**
     * Maps the successful DeepSeek API response data array to the ChatResponse DTO.
     * (Assumes OpenAI compatibility in structure).
     *
     * @param  array  $responseData  The decoded JSON response data from the API.
     * @param  bool  $wasJsonModeRequested  Indicates if the original request asked for JSON.
     * @return ChatResponse The populated response DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Assuming DeepSeek response structure matches OpenAI
        $content = $responseData['choices'][0]['message']['content'] ?? '';
        $decodedJson = null;

        if ($wasJsonModeRequested && ! empty($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
        }

        // Passare un array associativo al costruttore di SimpleDTO
        return new ChatResponse([
            'content' => $content,
            'finishReason' => $responseData['choices'][0]['finish_reason'] ?? 'unknown',
            'model' => $responseData['model'] ?? $this->model,
            'id' => $responseData['id'] ?? 'unknown',
            'usage' => $responseData['usage'] ?? null,
            'isJson' => $wasJsonModeRequested && ($decodedJson !== null),
            'decodedJsonContent' => $decodedJson,
            'rawResponse' => $responseData,
        ]);
    }

    /**
     * Handles non-successful (non-401) HTTP responses from DeepSeek.
     *
     * @param  Response  $response  The failed HTTP response.
     *
     * @throws LlmApiException Mapped API error.
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error'); // Attempt to get structured error

        $errorMessage = 'Unknown DeepSeek API Error';
        $errorCode = $statusCode; // Default to HTTP status code

        if (is_array($errorData)) {
            $errorMessage = $errorData['message'] ?? $response->body();
            $errorCode = $errorData['code'] ?? $statusCode;
        }

        // Assuming DeepSeek uses similar error codes/structure to OpenAI
        match ($statusCode) {
            // 401 is handled in the main chat method
            429 => throw new LlmApiException("DeepSeek API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode),
            400 => throw new LlmApiException("DeepSeek API Error - Bad Request ({$errorCode}): {$errorMessage}", $statusCode),
            500 => throw new LlmApiException("DeepSeek API Error - Internal Server Error ({$errorCode}): {$errorMessage}", $statusCode),
            default => throw new LlmApiException("DeepSeek API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of a successful DeepSeek response array.
     * (Assumes OpenAI compatibility).
     *
     * @param  array|null  $responseData  The decoded JSON data from the response.
     * @return bool True if the structure seems valid for a successful response, false otherwise.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Assuming same structure as OpenAI
        return isset($responseData['id'], $responseData['model'], $responseData['choices']) &&
            is_array($responseData['choices']) &&
            count($responseData['choices']) > 0 &&
            isset($responseData['choices'][0]['message'], $responseData['choices'][0]['message']['content']); // Check nested content
    }
}
