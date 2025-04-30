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


class DeepSeekClient implements LlmClientInterface
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
     * Configures the HTTP client with base URI and headers (similar to OpenAI).
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.deepseek.com'; // Or /v1 for OpenAI compatibility endpoint
        $timeout = $this->options['timeout'] ?? 30;

        return $this->httpFactory->baseUrl($baseUri)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the DeepSeek API.
     *
     * @param ChatRequest $request The DTO containing the request data.
     * @return ChatResponse The DTO containing the model's response.
     * @throws \Bramato\LaravelAi\Exceptions\LlmApiException On API errors.
     * @throws RequestException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // TODO: Implement DeepSeek API call logic (expected to be very similar to OpenAI)
        // 1. Prepare request payload
        // 2. Make POST request to /chat/completions endpoint
        // 3. Handle potential RequestException
        // 4. Parse the response
        // 5. Handle API errors
        // 6. Map successful response to ChatResponse DTO

        $payload = $this->buildPayload($request);

        try {
            // Use /v1/chat/completions if using the OpenAI compatibility endpoint base URI
            $endpoint = ($this->options['base_uri'] ?? '') === 'https://api.deepseek.com/v1'
                ? '/chat/completions'
                : '/chat/completions'; // Default endpoint if using base api.deepseek.com

            // Workaround: Check if base_uri ends with v1 and adjust endpoint. Cleaner way? Maybe store full endpoint path in config?
            if (str_ends_with($this->options['base_uri'] ?? '', '/v1')) {
                $endpoint = '/chat/completions'; // Use relative if base URI already includes /v1
            } else {
                $endpoint = '/chat/completions'; // Use full path if base URI is just the domain
            }


            $response = $this->httpClient->post($endpoint, $payload);

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
     * Builds the payload for the DeepSeek API request (similar to OpenAI).
     */
    protected function buildPayload(ChatRequest $request): array
    {
        // Expected to be identical or very similar to OpenAIClient::buildPayload
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
            // Check DeepSeek docs for specific supported options
            $allowedOptions = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed'];
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
        // Expected to be identical or very similar to OpenAIClient::mapResponseToDTO
        $content = $responseData['choices'][0]['message']['content'] ?? '';
        $decodedJson = null;

        if ($wasJsonModeRequested) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
        }

        return new ChatResponse(
            content: $content,
            finishReason: $responseData['choices'][0]['finish_reason'] ?? 'unknown',
            model: $responseData['model'] ?? $this->model,
            id: $responseData['id'] ?? 'unknown',
            usage: $responseData['usage'] ?? null,
            isJson: $wasJsonModeRequested,
            decodedJsonContent: $decodedJson,
            rawResponse: $responseData
        );
    }
}
