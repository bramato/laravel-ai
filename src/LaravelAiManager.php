<?php

namespace Bramato\LaravelAi;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Models\LlmModel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use InvalidArgumentException;

class LaravelAiManager
{
    protected Application $app;
    protected HttpClientFactory $http;
    protected array $config;
    protected array $clients = []; // Cache for resolved clients

    public function __construct(Application $app, HttpClientFactory $http, array $config)
    {
        $this->app = $app;
        $this->http = $http;
        $this->config = $config;
    }

    /**
     * Get the default LLM provider client instance.
     *
     * @return LlmClientInterface
     */
    public function defaultClient(): LlmClientInterface
    {
        return $this->provider($this->getDefaultProvider());
    }

    /**
     * Get a specific LLM provider client instance.
     *
     * @param string $name The name of the provider (e.g., 'openai', 'gemini').
     * @return LlmClientInterface
     * @throws InvalidArgumentException If the provider is not configured or cannot be resolved.
     */
    public function provider(string $name): LlmClientInterface
    {
        $name = $name ?: $this->getDefaultProvider();

        if (!isset($this->clients[$name])) {
            // Use the container to resolve the client via its specific binding
            try {
                $this->clients[$name] = $this->app->make("laravel-ai.client.{$name}");
            } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
                // Catch potential resolution errors (e.g., provider not configured correctly in SP)
                throw new InvalidArgumentException("Could not resolve LLM client for provider [{$name}]. Ensure it is configured correctly. Original error: " . $e->getMessage(), 0, $e);
            }

            // Ensure the resolved instance implements the correct interface
            if (!$this->clients[$name] instanceof LlmClientInterface) {
                throw new InvalidArgumentException("Resolved client for provider [{$name}] does not implement LlmClientInterface.");
            }
        }

        return $this->clients[$name];
    }

    /**
     * Send a chat request to the default LLM provider.
     *
     * @param ChatRequest $request
     * @return ChatResponse
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->defaultClient()->chat($request);
    }

    /**
     * Ask a simple question to the default provider or a specific model.
     * Returns only the content string.
     *
     * @param string $prompt The user's question or instruction.
     * @param LlmModel|null $model Optional: Specific LlmModel to use. Overrides default.
     * @param array $options Optional: Provider-specific options.
     * @return string The assistant's response content.
     */
    public function ask(string $prompt, ?LlmModel $model = null, array $options = []): string
    {
        return $this->performSimpleChat($prompt, null, $model, $options);
    }

    /**
     * Ask a simple question with a system message.
     * Returns only the content string.
     *
     * @param string $prompt The user's question or instruction.
     * @param string $systemMessage The system message.
     * @param LlmModel|null $model Optional: Specific LlmModel to use. Overrides default.
     * @param array $options Optional: Provider-specific options.
     * @return string The assistant's response content.
     */
    public function askWithSystem(string $prompt, string $systemMessage, ?LlmModel $model = null, array $options = []): string
    {
        return $this->performSimpleChat($prompt, $systemMessage, $model, $options);
    }

    /**
     * Extracts JSON from text using the default provider or a specific model.
     *
     * @param string $instruction A prompt explaining what to extract (e.g., "Extract user details").
     * @param string $text The text to extract JSON from.
     * @param LlmModel|null $model Optional: Specific LlmModel to use. Overrides default.
     * @param array $options Optional: Provider-specific options.
     * @return array|null The extracted data as an associative array, or null on failure.
     */
    public function extractJson(string $instruction, string $text, ?LlmModel $model = null, array $options = []): ?array
    {
        return $this->_performJsonExtraction($instruction, $text, $model, $options);
    }

    /**
     * Internal helper to perform the simple chat logic.
     */
    private function performSimpleChat(string $prompt, ?string $systemMessage, ?LlmModel $model, array $options): string
    {
        $client = $this->defaultClient(); // Start with the default client
        $requestOptions = $options; // Start with passed options

        if ($model) {
            // If a specific model is provided, get its provider's client
            $client = $this->provider($model->provider);
            // Add the specific model ID to the request options
            $requestOptions['model'] = $model->model_id;
        }

        // Prepare the request DTO
        $requestData = [
            'prompt' => $prompt,
            'options' => $requestOptions,
            // No history for simple chat
            // jsonMode defaults to false
        ];

        if ($systemMessage !== null) {
            $requestData['systemMessage'] = $systemMessage;
        }

        $request = new ChatRequest($requestData);

        // Get the response
        $response = $client->chat($request);

        // Return only the content
        return $response->content ?? ''; // Return empty string if content is null
    }

    /**
     * Internal helper to perform JSON extraction.
     */
    private function _performJsonExtraction(string $instruction, string $text, ?LlmModel $model, array $options): ?array
    {
        $client = $this->defaultClient(); // Start with the default client
        $requestOptions = $options; // Start with passed options

        if ($model) {
            // If a specific model is provided, get its provider's client
            $client = $this->provider($model->provider);
            // Add the specific model ID to the request options
            $requestOptions['model'] = $model->model_id;
        }

        // Construct a prompt suitable for JSON extraction
        $fullPrompt = <<<PROMPT
Extract the following information based on the instruction and format it as JSON:

Instruction: {$instruction}

Text:
"""
{$text}
"""

Respond ONLY with the valid JSON object.
PROMPT;

        // Prepare the request DTO, enforcing JSON mode
        $requestData = [
            'prompt' => $fullPrompt,
            'options' => $requestOptions,
            'jsonMode' => true,
            // No history or system message needed for this helper (system prompt baked into fullPrompt)
        ];

        $request = new ChatRequest($requestData);

        try {
            $response = $client->chat($request);
            $content = $response->content;

            if (empty($content)) {
                return null;
            }

            // Attempt to decode the JSON content
            $decoded = json_decode($content, true);

            // Check if decoding was successful and resulted in an array
            return is_array($decoded) ? $decoded : null;
        } catch (\Exception $e) {
            // Log the exception maybe?
            report($e); // Using Laravel's report helper
            return null; // Return null on any error during the API call or processing
        }
    }

    /**
     * Get the configuration for a specific provider.
     * Made public to be used by the Service Provider's client bindings.
     *
     * @param string $name
     * @return array
     * @throws InvalidArgumentException
     */
    public function getProviderConfig(string $name): array
    {
        $providerKey = ($name === 'anthropic') ? 'claude' : $name; // Handle potential alias internally if needed

        if (!isset($this->config['providers'][$providerKey])) {
            throw new InvalidArgumentException("LLM provider [{$name}] (using key '{$providerKey}') is not configured.");
        }

        return $this->config['providers'][$providerKey];
    }

    /**
     * Get the default provider name.
     *
     * @return string
     */
    protected function getDefaultProvider(): string
    {
        return $this->config['default'] ?? 'openai';
    }
}
