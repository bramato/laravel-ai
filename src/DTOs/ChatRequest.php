<?php

namespace Bramato\LaravelAi\DTOs;

use WendellAdriel\ValidatedDTO\Casting\ArrayCast;
use WendellAdriel\ValidatedDTO\Casting\BooleanCast;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

/**
 * Data Transfer Object for Chat API Requests.
 *
 * Encapsulates the validated data needed to make a request to any supported LLM provider.
 */
class ChatRequest extends ValidatedDTO
{
    /**
     * The main user prompt or message for the LLM.
     */
    public string $prompt;

    /**
     * An optional system message to guide the model's behavior, persona, or output format.
     */
    public ?string $systemMessage;

    /**
     * An array representing the conversation history.
     * Each element should be an associative array: ['role' => string, 'content' => string].
     * Roles are typically 'user' and 'assistant'.
     * Note: Provider-specific role requirements (e.g., alternation) apply.
     */
    public array $history;

    /**
     * An array of additional options to pass to the specific LLM provider's API.
     * Examples: ['temperature' => 0.7, 'max_tokens' => 500].
     * Refer to provider documentation for supported options.
     */
    public array $options;

    /**
     * Flag indicating whether to request JSON output from the model.
     * If true, the client will attempt to configure the API request for JSON
     * (where supported) and parse the response content as JSON.
     * Note: For some providers (like Claude), this requires specific prompt instructions.
     */
    public bool $jsonMode;

    /**
     * Optional array of image sources (URLs or base64 data URIs) to include with the prompt.
     * Currently primarily intended for use with vision-capable models (e.g., OpenAI GPT-4 Vision).
     * Example: ['https://example.com/image.jpg', 'data:image/png;base64,iVBORw...']
     *
     * @var array<int, string>|null
     */
    public ?array $images;

    /**
     * Defines the validation rules for the DTO properties.
     *
     * @return array<string, mixed> Validation rules.
     */
    protected function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1'], // Ensure prompt is not empty
            'systemMessage' => ['sometimes', 'nullable', 'string'],
            'history' => ['sometimes', 'array'],
            // Validate structure only if history is present and not empty
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant,system,model'], // Allow 'system'/'model' for flexibility, though clients map them
            'history.*.content' => ['required_with:history', 'string'],
            'options' => ['sometimes', 'array'],
            'jsonMode' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'nullable', 'array'], // Validate it's an array if present
            'images.*' => ['required_with:images', 'string', 'min:10'], // Basic check: each item is a non-empty string (URL or base64)
        ];
    }

    /**
     * Defines the default values for the DTO properties.
     *
     * @return array<string, mixed> Default values.
     */
    protected function defaults(): array
    {
        return [
            'systemMessage' => null,
            'history' => [],
            'options' => [],
            'jsonMode' => false,
            'images' => null, // Default images to null
        ];
    }

    /**
     * Defines the type casting for the DTO properties.
     *
     * @return array<string, object> Type casts.
     */
    protected function casts(): array
    {
        return [
            'history' => new ArrayCast,
            'options' => new ArrayCast,
            'jsonMode' => new BooleanCast,
            // 'images' doesn't strictly need a cast if it remains nullable array of strings
        ];
    }
}
