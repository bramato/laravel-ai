<?php

namespace Bramato\LaravelAi\DTOs;

use WendellAdriel\ValidatedDTO\SimpleDTO;

/**
 * Data Transfer Object for the response of the MultiTranslationService.
 */
class MultiTranslateResponseDto extends SimpleDTO
{
    /**
     * The detected or provided source language ISO code (e.g., 'en', 'it_IT').
     */
    public string $sourceLanguage;

    /**
     * An associative array mapping target language ISO codes to their translations.
     * The value can be null if the translation for a specific language failed.
     * Example: ['it' => 'Ciao', 'de' => 'Hallo', 'fr' => null]
     *
     * @var array<string, string|null>
     */
    public array $translations;

    /**
     * Defines the default values for the properties of the DTO.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'translations' => [],
        ];
    }

    /**
     * Defines the type casting for the properties of the DTO.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Although 'translations' is an array, SimpleDTO doesn't require explicit casting for it.
        // It will be assigned as is.
        return [];
    }
}
