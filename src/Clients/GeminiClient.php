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
 * Client implementation for interacting with Google's Gemini API.
 */
class GeminiClient implements LlmClientInterface
{
    /**
     * The configured HTTP client instance.
     */
    protected PendingRequest $httpClient;

    /**
     * The fully constructed API URL, including version, model, action, and API key.
     */
    protected string $apiUrl;

    /**
     * The configured API version (e.g., 'v1beta', 'v1').
     */
    protected string $apiVersion;

    /**
     * @param  HttpClientFactory  $httpFactory  The Laravel HTTP client factory.
     * @param  string  $apiKey  The Gemini API key.
     * @param  string  $model  The default Gemini model ID to use for requests.
     * @param  array  $options  Additional configuration options (e.g., base_uri, timeout, version, safety_settings).
     */
    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        // Default to v1beta as it supports more features like JSON mode
        $this->apiVersion = $this->options['version'] ?? 'v1beta';
        $this->apiUrl = $this->buildApiUrl();
        $this->httpClient = $this->configureHttpClient();
    }

    /**
     * Builds the specific API URL for the Gemini request.
     *
     * Includes base URI, API version, model, action (generateContent), and the API key as a query parameter.
     *
     * @return string The complete API URL.
     */
    protected function buildApiUrl(): string
    {
        $baseUri = rtrim($this->options['base_uri'] ?? 'https://generativelanguage.googleapis.com', '/');

        // The action determines the API endpoint (e.g., generateContent, generateAnswer).
        // We use generateContent as it's standard for multi-turn chat.
        $action = 'generateContent';

        return sprintf(
            '%s/%s/models/%s:%s?key=%s',
            $baseUri,
            $this->apiVersion,
            $this->model,
            $action,
            $this->apiKey
        );
    }

    /**
     * Configures the HTTP client with necessary headers and timeout.
     *
     * @return PendingRequest The configured HTTP client.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $timeout = $this->options['timeout'] ?? 60; // Gemini can sometimes be slower, increased default

        // Base URL is part of the full apiUrl for Gemini, so we don't set it here.
        // Key is also in the URL.
        return $this->httpFactory->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Gemini API.
     *
     * @param  ChatRequest  $request  The DTO containing the prompt, history, and options.
     * @return ChatResponse The DTO containing the API response.
     *
     * @throws AuthenticationException If the API key is invalid or permissions are insufficient (401, 403).
     * @throws InvalidResponseException If the API response structure is invalid or the request was blocked.
     * @throws LlmApiException For other API errors (rate limits, server errors, bad requests etc.).
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // Gemini's JSON mode requires the v1beta endpoint.
        if ($request->jsonMode && $this->apiVersion !== 'v1beta') {
            throw new LlmApiException('Gemini JSON mode requires the \'v1beta\' API version. Please configure it in laravel-ai.php options (providers.gemini.options.version).', 400);
        }

        try {
            // Build payload *inside* try-catch in case history validation fails
            $payload = $this->buildPayload($request);
        } catch (Throwable $e) {
            // Rethrow specific exceptions, wrap others
            if ($e instanceof LlmApiException) {
                throw $e;
            }
            throw new LlmApiException("Failed to build Gemini payload: {$e->getMessage()}", $e->getCode(), $e);
        }

        try {
            // The API URL already contains the key
            $response = $this->httpClient->post($this->apiUrl, $payload);

            // Handle specific HTTP errors first
            if ($response->status() === 401 || $response->status() === 403) {
                // Gemini might return 403 for invalid key or permissions
                $errorDetails = $response->json('error.message', 'Gemini Authentication/Permission failed (401/403)');
                throw new AuthenticationException($errorDetails, $response->status());
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response);
            }

            $responseData = $response->json();

            // Validate response structure OR check for blocking reasons
            if (! $this->isValidResponseStructure($responseData)) {
                // Check for prompt feedback blocking first, as it might have a 200 status
                if (isset($responseData['promptFeedback']['blockReason'])) {
                    $reason = $responseData['promptFeedback']['blockReason'];
                    $message = "Gemini request blocked due to safety settings: {$reason}.";
                    // Optionally include detailed ratings if present
                    if (! empty($responseData['promptFeedback']['safetyRatings'])) {
                        $message .= ' Safety Ratings: '.json_encode($responseData['promptFeedback']['safetyRatings']);
                    }
                    // We classify blocking as an InvalidResponseException
                    throw new InvalidResponseException($message);
                }

                // Check if it was an error response that somehow returned 200 OK
                if (isset($responseData['error']['message'])) {
                    $errorMessage = $responseData['error']['message'];
                    $errorCode = $responseData['error']['code'] ?? 'unknown_error_code';
                    throw new InvalidResponseException("Invalid response structure, received error details instead: ({$errorCode}) {$errorMessage}");
                }

                // General invalid structure if no block reason or error message found
                throw new InvalidResponseException('Invalid or incomplete response structure received from Gemini API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            // Handles connection errors or other client-side request issues
            throw new LlmApiException("HTTP Request Error calling Gemini API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others for clarity
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            // Wrap unexpected errors
            throw new LlmApiException("An unexpected error occurred during Gemini API interaction: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload array for the Gemini API request.
     *
     * This involves structuring the conversation history into Gemini's `contents` format,
     * ensuring alternating roles (`user`, `model`), merging consecutive messages from the same role,
     * handling system prompts, and mapping options to `generationConfig`.
     *
     * @param  ChatRequest  $request  The request DTO.
     * @return array The payload ready for JSON encoding.
     *
     * @throws LlmApiException If the message sequence is invalid for Gemini.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $contents = [];

        // --- Construct Message Stream ---
        // Combine system message, history, and prompt into a single ordered list.
        $messageStream = [];
        if ($request->systemMessage) {
            // Gemini's recommended approach for system instructions is complex.
            // For simplicity, we prepend it as a user message, followed by a placeholder model response.
            // This allows the actual history/prompt to start correctly.
            // See: https://ai.google.dev/docs/prompt_best_practices#add-instructions-before-examples
            // Note: A dedicated `system_instruction` field might be available in some API versions/models.
            $messageStream[] = ['role' => 'user', 'content' => $request->systemMessage];
            $messageStream[] = ['role' => 'model', 'content' => 'OK.']; // Placeholder to allow next turn to be user
        }
        $messageStream = array_merge($messageStream, $request->history);
        $messageStream[] = ['role' => 'user', 'content' => $request->prompt];

        // --- Build Gemini `contents` Array ---
        // This array requires alternating roles and merges consecutive messages of the same role.
        foreach ($messageStream as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Map our standard roles (user, assistant) to Gemini roles (user, model).
            // System messages were handled above.
            $geminiRole = ($role === 'user') ? 'user' : 'model';

            // Ensure the first message is always from the 'user' role.
            if (empty($contents) && $geminiRole !== 'user') {
                // This might happen if history starts with 'assistant' and no system prompt was given.
                // Prepend a dummy user message to satisfy the API requirement.
                $contents[] = ['role' => 'user', 'parts' => [['text' => '']]]; // Minimal user message
            }

            // Check if the current message role is the same as the last one in $contents.
            if (! empty($contents) && $contents[array_key_last($contents)]['role'] === $geminiRole) {
                // Merge content into the last message's parts array.
                $contents[array_key_last($contents)]['parts'][] = ['text' => $content];
            } else {
                // Start a new content block for the different role.
                $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $content]]];
            }
        }

        // Final validation: Ensure the conversation ends with a user message.
        if (empty($contents) || $contents[array_key_last($contents)]['role'] !== 'user') {
            // This *shouldn't* happen given the logic above appends the user prompt last,
            // but serves as a safeguard against unexpected history manipulation.
            throw new LlmApiException('Invalid message sequence for Gemini: Conversation content must end with a user message.', 400);
        }

        // --- Construct Payload ---
        $payload = [
            'contents' => $contents,
        ];

        // --- Handle Generation Config ---
        // Maps options from ChatRequest to Gemini's generationConfig object.
        $generationConfig = [];
        if (! empty($request->options)) {
            // Direct mapping for simple options
            $allowedOptions = ['temperature', 'topP', 'topK', 'candidateCount']; // Added candidateCount
            $generationConfig = array_intersect_key($request->options, array_flip($allowedOptions));

            // Map max_tokens to maxOutputTokens
            if (isset($request->options['max_tokens'])) {
                $generationConfig['maxOutputTokens'] = (int) $request->options['max_tokens'];
            }
            // Map stop sequence(s)
            if (isset($request->options['stop'])) {
                $generationConfig['stopSequences'] = is_array($request->options['stop']) ? $request->options['stop'] : [$request->options['stop']];
            }
        }

        // Handle JSON mode via response_mime_type
        if ($request->jsonMode) {
            // Requires v1beta endpoint (validation happens in chat() method)
            $generationConfig['response_mime_type'] = 'application/json';
            // Gemini also supports `responseSchema` within generationConfig for more complex JSON validation,
            // but we don't map this automatically from options currently.
        }

        if (! empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // --- Handle Safety Settings ---
        // Allow passing safety settings directly via the main client options.
        if (isset($this->options['safety_settings'])) {
            $payload['safetySettings'] = $this->options['safety_settings'];
        }

        return $payload;
    }

    /**
     * Maps the successful Gemini API response data array to the ChatResponse DTO.
     *
     * @param  array  $responseData  The decoded JSON response data from the API.
     * @param  bool  $wasJsonModeRequested  Indicates if the original request asked for JSON.
     * @return ChatResponse The populated response DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Extract primary content from the first candidate
        $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $responseData['candidates'][0]['finishReason'] ?? 'unknown';
        $id = 'gemini-'.bin2hex(random_bytes(8));
        $usage = null;
        if (isset($responseData['usageMetadata'])) {
            $usage = [
                'prompt_tokens' => $responseData['usageMetadata']['promptTokenCount'] ?? null,
                'completion_tokens' => $responseData['usageMetadata']['candidatesTokenCount'] ?? null,
                'total_tokens' => $responseData['usageMetadata']['totalTokenCount'] ?? null,
            ];
        }
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
            'finishReason' => $finishReason,
            'model' => $this->model,
            'id' => $id,
            'usage' => $usage,
            'isJson' => $wasJsonModeRequested && ($decodedJson !== null),
            'decodedJsonContent' => $decodedJson,
            'rawResponse' => $responseData,
        ]);
    }

    /**
     * Handles non-successful (non-401/403) HTTP responses from Gemini.
     *
     * @param  Response  $response  The failed HTTP response.
     *
     * @throws LlmApiException Mapped API error.
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error'); // Gemini typically wraps errors in an 'error' object

        $errorMessage = 'Unknown Gemini API Error';
        $errorCode = $statusCode; // Default to HTTP status

        if (is_array($errorData)) {
            $errorMessage = $errorData['message'] ?? $response->body();
            // Gemini error 'status' field often contains a string code like 'INVALID_ARGUMENT'
            $errorCode = $errorData['status'] ?? ($errorData['code'] ?? $statusCode); // Prefer status/code if available
        }

        // Throw specific exceptions based on common status codes
        match ($statusCode) {
            400 => throw new LlmApiException("Gemini API Error - Bad Request ({$errorCode}): {$errorMessage}", $statusCode),
            429 => throw new LlmApiException("Gemini API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode),
            // 401/403 are handled directly in the chat() method for AuthenticationException
            500 => throw new LlmApiException("Gemini API Error - Internal Server Error ({$errorCode}): {$errorMessage}", $statusCode),
            default => throw new LlmApiException("Gemini API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of a successful Gemini response array.
     *
     * Checks for the presence of candidate content.
     * Does not validate safety block reasons here, as that's handled separately in chat().
     *
     * @param  array|null  $responseData  The decoded JSON data from the response.
     * @return bool True if the structure seems valid for a successful response, false otherwise.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Check for the presence of the essential candidate text content.
        // We don't explicitly return false for blockReason here, as that might occur
        // even with a 200 status, and it's handled as a specific InvalidResponseException in chat().
        return isset($responseData['candidates'][0]['content']['parts'][0]['text']);
    }
}
