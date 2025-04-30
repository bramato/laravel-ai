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


class DeepSeekClient implements LlmClientInterface
{
    protected PendingRequest $httpClient;
    protected string $apiEndpoint;

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
     * Configures the HTTP client with base URI and headers (similar to OpenAI).
     */
    protected function configureHttpClient(): PendingRequest
    {
        // Default base URI for DeepSeek's OpenAI-compatible endpoint
        $baseUri = $this->options['base_uri'] ?? 'https://api.deepseek.com/v1';
        $timeout = $this->options['timeout'] ?? 30;

        return $this->httpFactory->baseUrl($baseUri)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->contentTypeJson() // Ensure Content-Type is set
            ->timeout($timeout);
    }

    /**
     * Determines the correct relative API endpoint path.
     * If the base_uri already ends with /v1, use a relative path.
     * Otherwise, use the full path.
     */
    protected function determineApiEndpoint(): string
    {
        $baseUri = rtrim($this->options['base_uri'] ?? 'https://api.deepseek.com/v1', '/');
        // Check if the effective baseUri already includes the version path
        if (str_ends_with($baseUri, '/v1')) {
            // Already configured with /v1, use relative path
            return '/chat/completions';
        } else {
            // Base URI is likely just the domain, use full path (assuming v1)
            // Or adjust if DeepSeek offers non-v1 endpoints via this client later
            return '/v1/chat/completions';
        }
    }


    /**
     * Sends a chat request to the DeepSeek API.
     *
     * @throws AuthenticationException
     * @throws InvalidResponseException
     * @throws LlmApiException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post($this->apiEndpoint, $payload);

            // Handle specific HTTP errors first before checking success
            if ($response->status() === 401) {
                throw new AuthenticationException(
                    $response->json('error.message', 'DeepSeek Authentication failed'),
                    $response->status()
                );
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response); // Handles other 4xx/5xx
            }

            $responseData = $response->json();

            // Validate structure AFTER checking for errors
            if (! $this->isValidResponseStructure($responseData)) {
                throw new InvalidResponseException('Invalid response structure received from DeepSeek API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            throw new LlmApiException("HTTP Request Error calling DeepSeek API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            throw new LlmApiException("An unexpected error occurred calling DeepSeek API: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload for the DeepSeek API request (similar to OpenAI).
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [],
        ];

        if ($request->systemMessage) {
            $payload['messages'][] = ['role' => 'system', 'content' => $request->systemMessage];
        }

        foreach ($request->history as $message) {
            if (isset($message['role'], $message['content'])) {
                $payload['messages'][] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        if (!empty($request->options)) {
            // Assuming DeepSeek uses the same options as OpenAI
            $allowedOptions = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed', 'stream']; // Added stream
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        if ($request->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Maps the successful DeepSeek API response to the ChatResponse DTO (similar to OpenAI).
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Assuming DeepSeek response structure matches OpenAI
        $content = $responseData['choices'][0]['message']['content'] ?? '';
        $decodedJson = null;

        if ($wasJsonModeRequested) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
        }

        return new ChatResponse(
            $content,
            $responseData['choices'][0]['finish_reason'] ?? 'unknown',
            $responseData['model'] ?? $this->model,
            $responseData['id'] ?? 'unknown',
            $responseData['usage'] ?? null,
            $wasJsonModeRequested,
            $decodedJson,
            $responseData
        );
    }

    /**
     * Handles non-successful HTTP responses.
     *
     * @throws LlmApiException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error');
        $errorMessage = is_array($errorData) && isset($errorData['message']) ? $errorData['message'] : $response->body();
        $errorCode = is_array($errorData) && isset($errorData['code']) ? $errorData['code'] : $statusCode;

        // Assuming DeepSeek uses similar error codes/structure to OpenAI
        match ($statusCode) {
            // 401 is handled in the main chat method
            429 => throw new LlmApiException("DeepSeek API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode), // Consider RateLimitException
            default => throw new LlmApiException("DeepSeek API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of the DeepSeek response (assuming OpenAI compatibility).
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
            isset($responseData['choices'][0]['message']['content']);
    }
}
