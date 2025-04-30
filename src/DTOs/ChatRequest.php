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
     * @var string
     */
    public string $prompt;

    /**
     * An optional system message to guide the model's behavior, persona, or output format.
     * @var string|null
     */
    public ?string $systemMessage;

    /**
     * An array representing the conversation history.
     * Each element should be an associative array: ['role' => string, 'content' => string].
     * Roles are typically 'user' and 'assistant'.
     * Note: Provider-specific role requirements (e.g., alternation) apply.
     * @var array
     */
    public array $history;

    /**
     * An array of additional options to pass to the specific LLM provider's API.
     * Examples: ['temperature' => 0.7, 'max_tokens' => 500].
     * Refer to provider documentation for supported options.
     * @var array
     */
    public array $options;

    /**
     * Flag indicating whether to request JSON output from the model.
     * If true, the client will attempt to configure the API request for JSON
     * (where supported) and parse the response content as JSON.
     * Note: For some providers (like Claude), this requires specific prompt instructions.
     * @var bool
     */
    public bool $jsonMode;

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
            'history' => new ArrayCast(),
            'options' => new ArrayCast(),
            'jsonMode' => new BooleanCast(),
        ];
    }
}
