<?php

namespace Bramato\LaravelAi\DTOs;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

class ChatRequest extends ValidatedDTO
{
    // The main user prompt/message.
    public string $prompt;

    // Optional system message to guide the model's behavior.
    public ?string $systemMessage;

    // Conversation history (array of ['role' => string, 'content' => string]).
    public array $history;

    // Additional options for the LLM provider (e.g., temperature, max_tokens).
    public array $options;

    // Flag to request JSON output from the model.
    public bool $jsonMode;

    /**
     * Defines the validation rules for the DTO.
     */
    protected function rules(): array
    {
        return [
            'prompt' => ['required', 'string'],
            'systemMessage' => ['sometimes', 'nullable', 'string'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant,system'], // Validate structure if history exists
            'history.*.content' => ['required_with:history', 'string'],
            'options' => ['sometimes', 'array'],
            'jsonMode' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Defines the default values for the properties of the DTO.
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
     * Defines the type casting for the properties of the DTO.
     */
    protected function casts(): array
    {
        return [
            'history' => 'array',
            'options' => 'array',
            'jsonMode' => 'bool',
        ];
    }
} 