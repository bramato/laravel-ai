<?php

namespace Bramato\LaravelAi\Clients;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
// use Bramato\LaravelAi\Exceptions\LlmApiException;
// use Bramato\LaravelAi\Exceptions\AuthenticationException;

class GeminiClient implements LlmClientInterface
{
    protected PendingRequest $httpClient;
    protected string $apiUrl;

    public function __construct(
        protected HttpClientFactory $httpFactory,
        protected string $apiKey,
        protected string $model,
        protected array $options = []
    ) {
        $this->apiUrl = $this->buildApiUrl();
        $this->httpClient = $this->configureHttpClient();
    }

    /**
     * Builds the API URL including the model and API key.
     */
    protected function buildApiUrl(): string
    {
        // Note: v1beta might be needed for JSON mode or other features
        $apiVersion = $this->options['version'] ?? 'v1beta';
        $baseUri = $this->options['base_uri'] ?? 'https://generativelanguage.googleapis.com';

        return sprintf(
            '%s/%s/models/%s:generateContent?key=%s',
            rtrim($baseUri, '/'),
            $apiVersion,
            $this->model,
            $this->apiKey
        );
    }

    /**
     * Configures the HTTP client.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $timeout = $this->options['timeout'] ?? 30;

        // Base URL is part of the full apiUrl for Gemini
        return $this->httpFactory->acceptJson()
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Gemini API.
     *
     * @param ChatRequest $request The DTO containing the request data.
     * @return ChatResponse The DTO containing the model's response.
     * @throws \Bramato\LaravelAi\Exceptions\LlmApiException On API errors.
     * @throws RequestException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // TODO: Implement Gemini API call logic
        // 1. Prepare request payload (contents structure) from ChatRequest DTO
        // 2. Handle generationConfig (temperature, max_tokens, jsonMode -> response_mime_type)
        // 3. Make POST request to the generated API URL
        // 4. Handle potential RequestException
        // 5. Parse the response (candidates structure)
        // 6. Handle API errors
        // 7. Map successful response to ChatResponse DTO

        $payload = $this->buildPayload($request);

        try {
            // The API URL already contains the key
            $response = $this->httpClient->post($this->apiUrl, $payload);

            if (! $response->successful()) {
                // TODO: Throw custom exception
                $response->throw();
            }

            return $this->mapResponseToDTO($response->json() ?? [], $request->jsonMode);
        } catch (RequestException $e) {
            // TODO: Wrap the exception
            throw $e;
        }
    }

    /**
     * Builds the payload for the Gemini API request.
     * Requires specific 'contents' structure.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        // TODO: Implement mapping from messages/history to Gemini's contents structure
        $contents = [];

        // Simple mapping for single prompt (needs enhancement for history/system message)
        if ($request->prompt) {
            $contents[] = [
                'parts' => [['text' => $request->prompt]]
                // TODO: Handle roles if mapping history
            ];
        }

        $payload = [
            'contents' => $contents,
        ];

        // Handle generationConfig
        $generationConfig = [];
        if (!empty($request->options)) {
            $allowedOptions = ['temperature', 'maxOutputTokens', 'topP', 'topK']; // Map max_tokens -> maxOutputTokens
            $mappedOptions = [];
            if (isset($request->options['max_tokens'])) {
                $mappedOptions['maxOutputTokens'] = $request->options['max_tokens'];
            }
            $mappedOptions += array_intersect_key($request->options, array_flip($allowedOptions));
            if (!empty($mappedOptions)) {
                $generationConfig = $mappedOptions;
            }
        }

        if ($request->jsonMode) {
            // Requires v1beta endpoint
            $generationConfig['response_mime_type'] = 'application/json';
            // TODO: Consider adding responseSchema support later
        }

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // TODO: Handle safetySettings if needed

        return $payload;
    }

    /**
     * Maps the successful Gemini API response to the ChatResponse DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // TODO: Implement correct mapping from Gemini response structure
        // Gemini response structure is different, often under `candidates` -> `content` -> `parts` -> `text`
        $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $responseData['candidates'][0]['finishReason'] ?? 'unknown';
        // Gemini response might not include model ID or usage stats directly in the same way
        $id = 'gemini-' . uniqid(); // Gemini doesn't provide a standard ID like OpenAI
        $usage = null; // TODO: Check if usage data is available (e.g., via promptFeedback?)

        $decodedJson = null;
        if ($wasJsonModeRequested && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            $rawContent = $responseData['candidates'][0]['content']['parts'][0]['text'];
            $decoded = json_decode($rawContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
                $content = $rawContent; // Keep original JSON string in content
            }
            // else: content remains the (potentially non-JSON) text part
        }

        return new ChatResponse(
            content: $content,
            finishReason: $finishReason,
            model: $this->model, // Use the configured model
            id: $id,
            usage: $usage,
            isJson: $wasJsonModeRequested,
            decodedJsonContent: $decodedJson,
            rawResponse: $responseData
        );
    }
}
