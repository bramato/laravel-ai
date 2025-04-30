<?php

namespace Bramato\LaravelAi\DTOs;

use WendellAdriel\ValidatedDTO\SimpleDTO;

class ChatResponse extends SimpleDTO
{
    // The main content of the response (text or raw JSON string).
    public string $content;

    // The reason the model stopped generating tokens.
    public string $finishReason;

    // The model that generated the response.
    public string $model;

    // A unique identifier for the chat completion.
    public string $id;

    // Token usage statistics (e.g., ['prompt_tokens' => 50, 'completion_tokens' => 100, 'total_tokens' => 150]).
    public ?array $usage;

    // Indicates if the content is expected to be a JSON string.
    public bool $isJson;

    // The decoded JSON content (array/object) if isJson is true and decoding was successful.
    public mixed $decodedJsonContent;

    // The original raw response from the provider's API.
    public ?array $rawResponse;

    /**
     * Defines the default values for the properties of the DTO.
     */
    protected function defaults(): array
    {
        return [
            'usage' => null,
            'isJson' => false,
            'decodedJsonContent' => null,
            'rawResponse' => null,
        ];
    }

    /**
     * Defines the type casting for the properties of the DTO.
     */
    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'isJson' => 'bool',
            'rawResponse' => 'array',
            // decodedJsonContent is mixed and set manually, no cast needed here.
        ];
    }
}
