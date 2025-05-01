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
 * Client implementation for interacting with OpenAI's Chat Completions API.
 */
class OpenAiClient implements LlmClientInterface
{
    /**
     * The configured HTTP client instance.
     */
    protected PendingRequest $httpClient;

    /**
     * @param  HttpClientFactory  $httpFactory  The Laravel HTTP client factory.
     * @param  string  $apiKey  The OpenAI API key.
     * @param  string  $model  The default OpenAI model ID to use for requests.
     * @param  array  $options  Additional configuration options (e.g., base_uri, timeout, organization).
     */
    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        $this->httpClient = $this->configureHttpClient();
    }

    /**
     * Configures the HTTP client with base URI, authentication, and specific headers.
     *
     * @return PendingRequest The configured HTTP client.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.openai.com/v1';
        $timeout = $this->options['timeout'] ?? 30;
        $organization = $this->options['organization'] ?? null;

        $client = $this->httpFactory->baseUrl($baseUri)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);

        if ($organization) {
            $client->withHeaders(['OpenAI-Organization' => $organization]);
        }

        return $client;
    }

    /**
     * Sends a chat request to the OpenAI API.
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
            $response = $this->httpClient->post('/chat/completions', $payload);

            // Handle specific HTTP errors first before checking success
            if ($response->status() === 401) {
                // Use a more specific error message if available from the response
                $errorMessage = $response->json('error.message', 'OpenAI Authentication failed - Invalid API Key');
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
                throw new InvalidResponseException('Invalid response structure received from OpenAI API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            // This catch handles connection errors or other client-side request issues
            throw new LlmApiException("HTTP Request Error calling OpenAI API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others for clarity
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            // Wrap unexpected errors (e.g., issues within the client logic itself)
            throw new LlmApiException("An unexpected error occurred during OpenAI API interaction: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload array for the OpenAI Chat Completions API request.
     *
     * This method constructs the JSON payload sent to the OpenAI API,
     * including model selection, message formatting (handling text and images),
     * options, and response format (JSON mode).
     *
     * @param  ChatRequest  $request  The request DTO containing all input details.
     * @return array The payload ready for JSON encoding.
     *
     * @throws InvalidArgumentException If images are provided but the selected/default model doesn't support vision and no fallback is available.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        // Determine model, prioritizing options, then potentially vision requirement
        $modelId = $request->options['model'] ?? $this->model;
        if (! empty($request->images) && ! $this->isVisionModel($modelId)) {
            // Attempt to switch to a default vision model if images provided but model doesn't support it
            // You might want a more sophisticated model selection logic here
            $visionModel = $this->getDefaultVisionModel();
            if ($visionModel) {
                $modelId = $visionModel;
            } else {
                // Or throw an exception if no vision model is available/configured
                throw new InvalidArgumentException("Images provided but the selected model '{$modelId}' does not support vision, and no default vision model is configured.");
            }
        }

        $payload = [
            'model' => $modelId, // Use determined model
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

        // Add the main user prompt and potentially images
        $userMessageContent = [];
        // Always add the text part first
        $userMessageContent[] = ['type' => 'text', 'content' => $request->prompt];

        // Add image URLs if provided
        if (! empty($request->images)) {
            foreach ($request->images as $imageUrl) {
                if (filter_var($imageUrl, FILTER_VALIDATE_URL) || str_starts_with($imageUrl, 'data:image')) {
                    $userMessageContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            // OpenAI API expects the URL directly in an object
                            'url' => $imageUrl,
                            // 'detail' => 'auto' // Optional: control image detail level (low, high, auto)
                        ],
                    ];
                } else {
                    // Handle invalid image URL/data URI? Log warning or throw exception?
                    report('Invalid image format provided in ChatRequest: ' . $imageUrl);
                    // For now, we'll just skip invalid ones.
                }
            }
        }

        // The user message content is now an array
        $payload['messages'][] = ['role' => 'user', 'content' => $userMessageContent];

        // Add allowed options from the request DTO
        if (! empty($request->options)) {
            $allowedOptions = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed', 'stream', 'logprobs', 'top_logprobs', 'user']; // Added more standard OpenAI options
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // Handle JSON mode parameter
        if ($request->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
            // Note: The user MUST ensure the prompt instructs the model to output JSON
            // when using this mode with OpenAI.
        }

        return $payload;
    }

    /**
     * Checks if a given OpenAI model ID is known to support vision capabilities.
     *
     * This uses a hardcoded list of known vision model prefixes/names.
     * Should be updated as new models are released by OpenAI.
     *
     * @param  string  $modelId  The model identifier (e.g., 'gpt-4-turbo', 'gpt-4o').
     * @return bool True if the model is known to support vision, false otherwise.
     */
    protected function isVisionModel(string $modelId): bool
    {
        // Add known OpenAI vision model identifiers here
        $visionModels = [
            'gpt-4-vision-preview',
            'gpt-4-turbo',
            'gpt-4-turbo-2024-04-09',
            'gpt-4o',
            'gpt-4o-2024-05-13',
            // Add other vision models as they become available
        ];

        return in_array(strtolower($modelId), array_map('strtolower', $visionModels));
    }

    /**
     * Attempts to retrieve a default vision model ID.
     *
     * Currently returns a hardcoded known default (e.g., 'gpt-4o').
     * Ideally, this could check configuration or query LlmModel in the future.
     *
     * @return string|null The model ID string if a default is found, otherwise null.
     */
    protected function getDefaultVisionModel(): ?string
    {
        // Prioritize a specific vision model from options if set
        // e.g., $this->options['default_vision_model']

        // Fallback to a known good default
        // This should ideally come from config or LlmModel query
        $knownVisionModels = ['gpt-4o', 'gpt-4-turbo'];
        foreach ($knownVisionModels as $modelId) {
            // Here you might check if the model is actually available/configured
            // For simplicity, just return the first known one
            return $modelId;
        }

        return null;
    }

    /**
     * Maps the successful OpenAI API response data array to the ChatResponse DTO.
     *
     * @param  array  $responseData  The decoded JSON response data from the API.
     * @param  bool  $wasJsonModeRequested  Indicates if the original request asked for JSON.
     * @return ChatResponse The populated response DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
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
     * Handles non-successful (non-401) HTTP responses.
     *
     * @param  Response  $response  The failed HTTP response.
     *
     * @throws LlmApiException Mapped API error.
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error'); // Attempt to get structured error

        $errorMessage = 'Unknown OpenAI API Error';
        $errorCode = $statusCode; // Default to HTTP status code

        if (is_array($errorData)) {
            $errorMessage = $errorData['message'] ?? $response->body(); // Use message if available, else raw body
            $errorCode = $errorData['code'] ?? $statusCode; // Use specific code if available
        }

        // Throw specific exceptions based on common status codes
        match ($statusCode) {
            429 => throw new LlmApiException("OpenAI API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode), // Consider a dedicated RateLimitException
            400 => throw new LlmApiException("OpenAI API Error - Bad Request ({$errorCode}): {$errorMessage}", $statusCode),
            500 => throw new LlmApiException("OpenAI API Error - Internal Server Error ({$errorCode}): {$errorMessage}", $statusCode),
            // Catch other client/server errors
            default => throw new LlmApiException("OpenAI API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of a successful OpenAI response array.
     *
     * @param  array|null  $responseData  The decoded JSON data from the response.
     * @return bool True if the structure seems valid for a successful response, false otherwise.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Basic check for essential fields in a successful response
        return isset($responseData['id'], $responseData['model'], $responseData['choices']) &&
            is_array($responseData['choices']) &&
            count($responseData['choices']) > 0 &&
            isset($responseData['choices'][0]['message'], $responseData['choices'][0]['message']['content']); // Check nested content existence
    }
}
