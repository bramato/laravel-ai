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

class ClaudeClient implements LlmClientInterface
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
     * Configures the HTTP client with base URI and required Claude headers.
     */
    protected function configureHttpClient(): PendingRequest
    {
        $baseUri = $this->options['base_uri'] ?? 'https://api.anthropic.com/v1';
        $timeout = $this->options['timeout'] ?? 30;
        $apiVersion = $this->options['version'] ?? '2023-06-01'; // Required by Claude

        if (empty($this->apiKey)) {
            // TODO: Throw AuthenticationException
            throw new \InvalidArgumentException('Claude API Key is missing.');
        }
        if (empty($apiVersion)) {
            // TODO: Throw LlmApiException or InvalidArgumentException
            throw new \InvalidArgumentException('Claude API Version (anthropic-version header) is missing.');
        }

        return $this->httpFactory->baseUrl($baseUri)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $apiVersion,
            ])
            ->acceptJson()
            ->contentTypeJson() // Claude expects application/json
            ->timeout($timeout);
    }

    /**
     * Sends a chat request to the Claude API.
     *
     * @param ChatRequest $request The DTO containing the request data.
     * @return ChatResponse The DTO containing the model's response.
     * @throws \Bramato\LaravelAi\Exceptions\LlmApiException On API errors.
     * @throws RequestException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // TODO: Implement Claude API call logic
        // 1. Prepare request payload (messages, model, max_tokens, options)
        // 2. Make POST request to /messages endpoint
        // 3. Handle potential RequestException
        // 4. Parse the response
        // 5. Handle API errors
        // 6. Map successful response to ChatResponse DTO

        $payload = $this->buildPayload($request);

        try {
            $response = $this->httpClient->post('/messages', $payload);

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
     * Builds the payload for the Claude API request.
     */
    protected function buildPayload(ChatRequest $request): array
    {
        // TODO: Refine payload building, handle options correctly
        $payload = [
            'model' => $this->model,
            'messages' => [],
            // 'max_tokens' is required by Claude API
            'max_tokens' => $request->options['max_tokens'] ?? 1024, // Default value, make configurable?
        ];

        // Note: Claude doesn't have a dedicated 'system' role like OpenAI.
        // System prompts should be placed before the first 'user' message if needed,
        // or potentially using the 'system' parameter if available in the API version.
        if ($request->systemMessage) {
            // Option 1: Prepend as a message (check Claude docs for best practice)
            // $payload['messages'][] = ['role' => 'user', 'content' => 'System Instruction: '.$request->systemMessage];
            // Option 2: Use 'system' parameter if supported
            // $payload['system'] = $request->systemMessage;
            // For now, let's assume system messages are handled by the user within history/prompt.
        }

        // Add history messages (user/assistant)
        foreach ($request->history as $message) {
            if (isset($message['role'], $message['content']) && in_array($message['role'], ['user', 'assistant'])) {
                $payload['messages'][] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        // Add the main prompt (must be 'user' role)
        $payload['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        // Add other allowed options (e.g., temperature, top_p, top_k, stop_sequences)
        if (!empty($request->options)) {
            $allowedOptions = ['temperature', 'top_p', 'top_k', 'stop_sequences'];
            $payload += array_intersect_key($request->options, array_flip($allowedOptions));
        }

        // Claude does not have a specific 'json_mode' parameter.
        // Achieving JSON output relies on prompt engineering.
        if ($request->jsonMode) {
            // We might want to modify the prompt here or log a warning,
            // as just setting jsonMode=true won't enforce JSON for Claude.
        }

        // Ensure max_tokens is set (either from options or default)
        if (!isset($payload['max_tokens'])) {
            $payload['max_tokens'] = 1024; // Fallback default
        }

        return $payload;
    }

    /**
     * Maps the successful Claude API response to the ChatResponse DTO.
     */
    protected function mapResponseToDTO(array $responseData, bool $wasJsonModeRequested): ChatResponse
    {
        // TODO: Refine mapping based on Claude's exact response structure
        // Claude response: `content` is an array, usually with one text block.
        $content = '';
        if (isset($responseData['content']) && is_array($responseData['content']) && isset($responseData['content'][0]['text'])) {
            $content = $responseData['content'][0]['text'];
        }

        $decodedJson = null;
        if ($wasJsonModeRequested) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $decoded;
            }
        }

        return new ChatResponse(
            content: $content,
            finishReason: $responseData['stop_reason'] ?? 'unknown',
            model: $responseData['model'] ?? $this->model,
            id: $responseData['id'] ?? 'unknown',
            usage: $responseData['usage'] ?? null, // Claude provides usage {input_tokens, output_tokens}
            isJson: $wasJsonModeRequested,
            decodedJsonContent: $decodedJson,
            rawResponse: $responseData
        );
    }
}
