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

class GeminiClient implements LlmClientInterface
{
    protected PendingRequest $httpClient;
    protected string $apiUrl;
    protected string $apiVersion;

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
     * Builds the API URL including the model and API key.
     */
    protected function buildApiUrl(): string
    {
        $baseUri = rtrim($this->options['base_uri'] ?? 'https://generativelanguage.googleapis.com', '/');

        // The action depends on the API version (generateContent vs generateAnswer)
        // Sticking to generateContent which is common for chat
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
     * Configures the HTTP client.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $timeout = $this->options['timeout'] ?? 60; // Gemini can sometimes be slower

        // Base URL is part of the full apiUrl for Gemini
        return $this->httpFactory->acceptJson()
            ->contentTypeJson()
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Gemini API.
     *
     * @throws AuthenticationException
     * @throws InvalidResponseException
     * @throws LlmApiException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // Gemini's JSON mode requires the v1beta endpoint.
        if ($request->jsonMode && $this->apiVersion !== 'v1beta') {
            throw new LlmApiException('Gemini JSON mode requires the \'v1beta\' API version. Please configure it in laravel-ai.php options.', 400);
        }

        $payload = $this->buildPayload($request);

        try {
            // The API URL already contains the key
            $response = $this->httpClient->post($this->apiUrl, $payload);

            // Handle specific HTTP errors first before checking success
            if ($response->status() === 401 || $response->status() === 403) {
                // Gemini might return 403 for invalid key/permissions
                $errorDetails = $response->json('error.message', 'Gemini Authentication/Permission failed');
                throw new AuthenticationException($errorDetails, $response->status());
            }

            if ($response->failed()) {
                $this->handleErrorResponse($response);
            }

            $responseData = $response->json();

            if (! $this->isValidResponseStructure($responseData)) {
                // Check for prompt feedback blocking as a specific case
                if (isset($responseData['promptFeedback']['blockReason'])) {
                    $reason = $responseData['promptFeedback']['blockReason'];
                    $message = "Gemini request blocked due to: {$reason}.";
                    if (!empty($responseData['promptFeedback']['safetyRatings'])) {
                        $message .= " Safety Ratings: " . json_encode($responseData['promptFeedback']['safetyRatings']);
                    }
                    throw new InvalidResponseException($message);
                }
                throw new InvalidResponseException('Invalid or incomplete response structure received from Gemini API.');
            }

            return $this->mapResponseToDTO($responseData, $request->jsonMode);
        } catch (RequestException $e) {
            throw new LlmApiException("HTTP Request Error calling Gemini API: {$e->getMessage()}", $e->getCode(), $e);
        } catch (Throwable $e) {
            // Rethrow our own exceptions, wrap others
            if ($e instanceof LlmApiException || $e instanceof AuthenticationException || $e instanceof InvalidResponseException) {
                throw $e;
            }
            throw new LlmApiException("An unexpected error occurred calling Gemini API: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Builds the payload for the Gemini API request.
     * Requires specific 'contents' structure with alternating roles.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        $contents = [];
        $currentRole = 'user'; // Start with user
        $currentParts = [];

        // Combine system message, history, and prompt into a single stream
        $messageStream = [];
        if ($request->systemMessage) {
            // Gemini prefers system instructions within the first user message or specific 'system_instruction' field (v1beta)
            // Prepending to the first user turn is a common strategy if system_instruction isn't used.
            // We will add it before history for simplicity here.
            // Note: The official recommendation might evolve.
            $messageStream[] = ['role' => 'user', 'content' => $request->systemMessage];
            $messageStream[] = ['role' => 'model', 'content' => 'OK.']; // Need a model response to continue user turn
        }
        $messageStream = array_merge($messageStream, $request->history);
        $messageStream[] = ['role' => 'user', 'content' => $request->prompt];

        // Build Gemini's `contents` array
        foreach ($messageStream as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Map roles (user -> user, assistant/system -> model)
            $geminiRole = ($role === 'user') ? 'user' : 'model';

            if (empty($contents) && $geminiRole !== 'user') {
                // First message MUST be 'user'
                $contents[] = ['role' => 'user', 'parts' => [['text' => ' ']]]; // Add dummy user message if history starts with model
            }

            if (!empty($contents) && $contents[array_key_last($contents)]['role'] === $geminiRole) {
                // Merge consecutive messages of the same role (required by Gemini)
                $contents[array_key_last($contents)]['parts'][] = ['text' => $content];
            } else {
                // Start a new content block for the different role
                $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $content]]];
            }
        }

        // Ensure the last message is from the user
        if (empty($contents) || $contents[array_key_last($contents)]['role'] !== 'user') {
            // This case should ideally not happen with our logic, but as a safeguard:
            throw new LlmApiException('Invalid message sequence for Gemini: Last message must be from user.', 400);
            // Alternatively, add a dummy user message? Might skew results.
        }

        $payload = [
            'contents' => $contents,
        ];

        // Handle generationConfig
        $generationConfig = [];
        if (!empty($request->options)) {
            $allowedOptions = ['temperature', 'topP', 'topK']; // Note: stopSequences, candidateCount also exist
            $generationConfig = array_intersect_key($request->options, array_flip($allowedOptions));

            if (isset($request->options['max_tokens'])) {
                $generationConfig['maxOutputTokens'] = (int) $request->options['max_tokens'];
            }
            if (isset($request->options['stop'])) {
                $generationConfig['stopSequences'] = is_array($request->options['stop']) ? $request->options['stop'] : [$request->options['stop']];
            }
        }

        if ($request->jsonMode) {
            // Requires v1beta endpoint (checked in chat() method)
            $generationConfig['response_mime_type'] = 'application/json';
            // TODO: Consider adding responseSchema support later from options
        }

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // TODO: Handle safetySettings if needed (can be passed via options)
        if (isset($this->options['safety_settings'])) {
            $payload['safetySettings'] = $this->options['safety_settings'];
        }

        return $payload;
    }

    /**
     * Maps the successful Gemini API response to the ChatResponse DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // Gemini response structure: `candidates` -> `content` -> `parts` -> `text`
        $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $responseData['candidates'][0]['finishReason'] ?? 'unknown';
        $id = 'gemini-' . bin2hex(random_bytes(8)); // Gemini doesn't provide a standard ID

        // Attempt to extract usage data (token counts might be under promptFeedback or usageMetadata)
        $usage = null;
        if (isset($responseData['usageMetadata'])) {
            $usage = [
                'prompt_tokens' => $responseData['usageMetadata']['promptTokenCount'] ?? null,
                'completion_tokens' => $responseData['usageMetadata']['candidatesTokenCount'] ?? null,
                'total_tokens' => $responseData['usageMetadata']['totalTokenCount'] ?? null,
            ];
        } elseif (isset($responseData['promptFeedback']['tokenCount'])) { // Older format?
            $usage = [
                'prompt_tokens' => $responseData['promptFeedback']['tokenCount'] ?? null,
                'completion_tokens' => null, // Not typically available here
                'total_tokens' => null,
            ];
        }

        $decodedJson = null;
        if ($wasJsonModeRequested && !empty($content)) {
            // When response_mime_type is application/json, the text *should* be valid JSON.
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
                // Keep original JSON string in $content for consistency? Or use $decodedJson?
                // Let's keep $content as the raw string for now.
            }
            // else: $decodedJson remains null, $content has the (potentially invalid) JSON string
        }

        return new ChatResponse(
            $content,
            finishReason: $finishReason,
            model: $this->model, // Use the configured model
            id: $id,
            usage: $usage,
            isJson: $wasJsonModeRequested && ($decodedJson !== null),
            decodedJsonContent: $decodedJson,
            rawResponse: $responseData
        );
    }

    /**
     * Handles non-successful HTTP responses from Gemini.
     *
     * @throws LlmApiException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json('error');

        $errorMessage = 'Unknown Gemini API Error';
        $errorCode = $statusCode; // Default to HTTP status

        if (is_array($errorData)) {
            $errorMessage = $errorData['message'] ?? $response->body();
            // Gemini error status often repeats HTTP status but might have specific codes like 'INVALID_ARGUMENT'
            $errorCode = $errorData['status'] ?? ($errorData['code'] ?? $statusCode); // Prefer status/code field if present
        }

        match ($statusCode) {
            400 => throw new LlmApiException("Gemini API Error - Bad Request ({$errorCode}): {$errorMessage}", $statusCode),
            429 => throw new LlmApiException("Gemini API Error - Rate Limit Exceeded ({$errorCode}): {$errorMessage}", $statusCode),
            // 401/403 handled in chat()
            default => throw new LlmApiException("Gemini API Error ({$errorCode}, status:{$statusCode}): {$errorMessage}", $statusCode),
        };
    }

    /**
     * Validates the basic structure of the Gemini response.
     */
    protected function isValidResponseStructure(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Check for successful response structure
        return isset($responseData['candidates'][0]['content']['parts'][0]['text']);

        // We don't explicitly return false for blockReason here, handled in chat()
    }
}
