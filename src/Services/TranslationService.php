<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use InvalidArgumentException;

/**
 * Service for translating text.
 */
class TranslationService extends BaseAiService
{
    /**
     * Translate the given text to the target language.
     *
     * @param  string  $text  The text to translate.
     * @param  string  $targetLanguage  The target language (e.g., "French", "Español", "it").
     * @param  string|null  $sourceLanguage  Optional: The source language (e.g., "English", "de"). If null, the LLM will attempt auto-detection.
     * @param  LlmModel|null  $model  Optional: Specific LlmModel to use.
     * @param  array  $options  Optional: Provider-specific options.
     * @return string|null The translated text, or null on failure.
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?LlmModel $model = null,
        array $options = []
    ): ?string {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text to translate cannot be empty.');
        }
        if (trim($targetLanguage) === '') {
            throw new InvalidArgumentException('Target language cannot be empty.');
        }

        // Build the core instruction
        $instruction = "Translate the following text into {$targetLanguage}.";

        // Specify source language if provided
        if ($sourceLanguage && trim($sourceLanguage) !== '') {
            $instruction .= " Assume the original text is in {$sourceLanguage}.";
        } else {
            $instruction .= ' Try to auto-detect the source language if needed.';
        }

        // Instruction for the AI: only output the translation
        $instruction .= ' Respond ONLY with the translated text.';

        // Use ask() helper as system message isn't strictly necessary here,
        // the instruction within the prompt is clear enough.
        $prompt = <<<PROMPT
{$instruction}

Text to translate:
"""
{$text}
"""

Translated text:
PROMPT;

        try {
            // Use the LaravelAiManager's ask helper
            $translation = $this->laravelAi->ask(
                prompt: $prompt,
                model: $model, // Pass the optional model
                options: $options // Pass the optional options
            );

            // Return the trimmed translation, or null if empty/error
            return ! empty($translation) ? trim($translation) : null;
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            report($e); // Using Laravel's report helper

            return null; // Return null on error
        }
    }
}
