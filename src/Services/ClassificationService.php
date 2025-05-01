<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use InvalidArgumentException;

/**
 * Service for classifying text into predefined categories.
 */
class ClassificationService extends BaseAiService
{
    /**
     * Classify the given text into one of the provided categories.
     *
     * @param string $text The text to classify.
     * @param array<int, string> $categories An array of possible categories.
     * @param LlmModel|null $model Optional: Specific LlmModel to use.
     * @param array $options Optional: Provider-specific options.
     * @return string|null The chosen category string, or null if classification fails or returns an invalid category.
     */
    public function classify(string $text, array $categories, ?LlmModel $model = null, array $options = []): ?string
    {
        if (empty($categories)) {
            throw new InvalidArgumentException('Categories array cannot be empty.');
        }

        // Ensure categories are simple strings and prepare for prompt
        $processedCategories = [];
        foreach ($categories as $category) {
            if (!is_string($category) || trim($category) === '') {
                throw new InvalidArgumentException('Categories must be non-empty strings.');
            }
            $processedCategories[] = trim($category);
        }

        $categoryList = "- " . implode("\n- ", $processedCategories);

        // Construct the prompt
        $systemMessage = <<<PROMPT
You are a text classification assistant. Your task is to classify the given text into one of the following predefined categories. Respond ONLY with the single category name that best fits the text.

Available Categories:
{$categoryList}
PROMPT;

        $prompt = <<<PROMPT
Classify the following text:

"""
{$text}
"""

Category:
PROMPT;

        try {
            // Use the LaravelAiManager's askWithSystem helper
            $responseContent = $this->laravelAi->askWithSystem(
                prompt: $prompt,
                systemMessage: $systemMessage,
                model: $model, // Pass the optional model
                options: $options // Pass the optional options
            );

            // Validate the response
            $trimmedResponse = trim($responseContent);
            if (in_array($trimmedResponse, $processedCategories, true)) {
                return $trimmedResponse;
            }

            // Optional: Log or handle cases where the LLM returned something unexpected
            // report("LLM returned an invalid category: '{$trimmedResponse}' for text: '{$text}'");

            return null; // Return null if the response is not a valid category

        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            report($e); // Using Laravel's report helper
            return null; // Return null on error
        }
    }

    // Future: Implement tag() method for multi-label classification
    /*
    public function tag(string $text, array $possibleTags, ?LlmModel $model = null, array $options = []): array
    {
        // ... implementation ...
        return [];
    }
    */
}
