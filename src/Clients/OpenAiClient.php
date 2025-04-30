<?php

namespace Bramato\LaravelAi\Clients;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
// use Bramato\LaravelAi\Exceptions\LlmApiException; // To be created
// use Bramato\LaravelAi\Exceptions\AuthenticationException; // To be created

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
     * @param ChatRequest $request The DTO containing the request data.
     * @return ChatResponse The DTO containing the model's response.
     * @throws \Bramato\LaravelAi\Exceptions\LlmApiException On API errors.
     * @throws RequestException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // TODO: Implement OpenAI API call logic
        // 1. Prepare request payload from ChatRequest DTO (messages, model, options, jsonMode)
        // 2. Make POST request to /chat/completions endpoint
        // 3. Handle potential RequestException
        // 4. Parse the response
        // 5. Handle API errors (check status code, error messages in response body)
        // 6. Map successful response to ChatResponse DTO

        // Placeholder - Replace with actual implementation
        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post('/chat/completions', $payload);

            if (! $response->successful()) {
                // TODO: Throw custom exception (e.g., LlmApiException) based on response status/body
                $response->throw(); // Throws Illuminate\Http\Client\RequestException for now
            }

            return $this->mapResponseToDTO($response->json(), $request->jsonMode);
        } catch (RequestException $e) {
            // TODO: Wrap the exception or re-throw a custom one (e.g., LlmApiException)
            // Potentially check for specific status codes (401 -> AuthenticationException)
            throw $e;
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
            content: $content,
            finishReason: $responseData['choices'][0]['finish_reason'] ?? 'unknown',
            model: $responseData['model'] ?? $this->model,
            id: $responseData['id'] ?? 'unknown',
            usage: $responseData['usage'] ?? null,
            isJson: $wasJsonModeRequested, // Indicate JSON was *requested*
            decodedJsonContent: $decodedJson,
            rawResponse: $responseData
        );
    }
}
