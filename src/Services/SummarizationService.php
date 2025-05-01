<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use InvalidArgumentException;

/**
 * Service for summarizing text.
 */
class SummarizationService extends BaseAiService
{
    /**
     * Summarize the given text.
     *
     * @param string $text The text to summarize.
     * @param string|null $format Optional instruction for the summary format (e.g., "paragraph", "bullet points", "single sentence").
     * @param int|null $lengthTarget Optional target length (e.g., number of words or sentences). Interpretation depends on the LLM.
     * @param LlmModel|null $model Optional: Specific LlmModel to use.
     * @param array $options Optional: Provider-specific options.
     * @return string|null The summary text, or null on failure.
     */
    public function summarize(
        string $text,
        ?string $format = null,
        ?int $lengthTarget = null,
        ?LlmModel $model = null,
        array $options = []
    ): ?string {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text to summarize cannot be empty.');
        }

        // Build the core instruction
        $instruction = "Summarize the following text.";

        // Add format constraints if provided
        if ($format) {
            $instruction .= " The summary should be in the format of: {$format}.";
        }

        // Add length constraints if provided
        if ($lengthTarget) {
            // Note: How LLMs interpret length targets can vary significantly.
            // Common interpretations include words, sentences, or paragraphs.
            // We'll make it a general request.
            $instruction .= " Aim for a length of approximately {$lengthTarget} (e.g., words or sentences).";
        }

        // Construct the system message and prompt
        $systemMessage = <<<PROMPT
You are a text summarization assistant. Follow the instructions precisely to summarize the provided text.
Instruction: {$instruction}
PROMPT;

        $prompt = <<<PROMPT
Text to summarize:

"""
{$text}
"""

Summary:
PROMPT;

        try {
            // Use the LaravelAiManager's askWithSystem helper
            $summary = $this->laravelAi->askWithSystem(
                prompt: $prompt,
                systemMessage: $systemMessage,
                model: $model, // Pass the optional model
                options: $options // Pass the optional options
            );

            // Return the trimmed summary, or null if empty/error
            return !empty($summary) ? trim($summary) : null;
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            report($e); // Using Laravel's report helper
            return null; // Return null on error
        }
    }
}
