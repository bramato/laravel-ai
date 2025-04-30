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

class OpenAiClient implements LlmClientInterface
{
    protected PendingRequest $httpClient;

    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        $this->httpClient = $this->configureHttpClient();
    }

    /**
     * Configures the HTTP client with base URI and headers.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.openai.com/v1';
        $timeout = $this->options['timeout'] ?? 30;
        $organization = $this->options['organization'] ?? null;

        $client = $this->httpFactory->baseUrl($baseUri)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($timeout);

        if ($organization) {
            $client->withHeaders(['OpenAI-Organization' => $organization]);
        }

        return $client;
    }

    /**
     * Sends a chat request to the OpenAI API.
     *
     * @throws AuthenticationException
     * @throws InvalidResponseException
     * @throws LlmApiException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post('/chat/completions', $payload);

            // Handle specific HTTP errors first before checking success
            if ($response->status() === 401) {
                throw new AuthenticationException(
                    $response->json('error.message', 'Authentication failed'),
                    $response->status()
                );
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response); // Handles other 4xx/5xx
            }

            $responseData = $response->json();

            // Validate structure AFTER checking for errors
            if (! $this->isValidResponseStructure($responseData)) {
                throw new InvalidResponseException('Invalid response structure received from OpenAI API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            // This catch might now only handle connection errors or non-401 HTTP errors if not caught above
            // Re-throw as a generic LlmApiException or handle specific cases
            throw new LlmApiException("HTTP Request Error calling OpenAI API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            throw new LlmApiException("An unexpected error occurred: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload for the OpenAI API request.
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

        // Add history messages
        foreach ($request->history as $message) {
            // Basic validation - ensure role and content exist (already validated by DTO rules)
            if (isset($message['role'], $message['content'])) {
                $payload['messages'][] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        // Add the main prompt
        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        // Add options (temperature, max_tokens, etc.) - filter only known OpenAI params?
        if (!empty($request->options)) {
            // Example: only allow specific OpenAI options
            $allowedOptions = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed'];
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // Handle JSON mode
        if ($request->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
            // Ensure the prompt instructs JSON output (crucial for OpenAI's JSON mode)
            // We might add a check here or rely on the user providing the correct prompt.
        }

        return $payload;
    }

    /**
     * Maps the successful OpenAI API response to the ChatResponse DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        $content = $responseData['choices'][0]['message']['content'] ?? '';
        $decodedJson = null;

        if ($wasJsonModeRequested) {
            // Attempt to decode if JSON mode was requested
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
            // else: leave $decodedJson as null, content remains raw string
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
     * @throws AuthenticationException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        // Ensure error data exists before accessing keys
        $errorData = $response->json('error');
        $errorMessage = is_array($errorData) && isset($errorData['message']) ? $errorData['message'] : $response->body();
        $errorCode = is_array($errorData) && isset($errorData['code']) ? $errorData['code'] : $statusCode;

        match ($statusCode) {
            // 401 should ideally be caught before this method
            // 401 => throw new AuthenticationException("OpenAI API Error ({$errorCode}): {$errorMessage}", $statusCode),
            429 => throw new LlmApiException("OpenAI API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode), // Consider RateLimitException
            // Catch other client/server errors
            default => throw new LlmApiException("OpenAI API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of the OpenAI response.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        return isset($responseData['id'], $responseData['model'], $responseData['choices']) &&
            is_array($responseData['choices']) &&
            count($responseData['choices']) > 0 &&
            isset($responseData['choices'][0]['message']['content']);
    }
}
