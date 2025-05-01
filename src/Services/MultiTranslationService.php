<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\MultiTranslateResponseDto;
use Bramato\LaravelAi\Enums\Language;
use Bramato\LaravelAi\Models\LlmModel;
use InvalidArgumentException;

/**
 * Service for performing multi-language translations using a single LLM call.
 */
class MultiTranslationService extends BaseAiService
{
    /**
     * Translates the given text into multiple target languages simultaneously.
     *
     * This method attempts to perform all translations in a single call to a capable LLM,
     * requesting a structured JSON output.
     *
     * @param  string  $text  The text to translate.
     * @param  array<int, Language|string>  $targets  An array of target languages (Language enum cases or ISO codes).
     * @param  string|null  $sourceLanguage  Optional: The source language ISO code. If null, attempts auto-detection via a preliminary LLM call.
     * @param  LlmModel|null  $model  Optional: Specific LlmModel to use. Must support JSON mode and preferably have a large context window.
     *                                If null, a suitable model supporting JSON mode will be automatically selected.
     * @param  array  $options  Optional: Provider-specific options for the main LLM call.
     * @return MultiTranslateResponseDto|null The DTO containing the source language and translations, or null if the process fails (e.g., language detection error, LLM error, invalid JSON response).
     *
     * @throws InvalidArgumentException If input parameters ($text, $targets) are invalid, or if the provided $model does not support JSON mode, or if no suitable model can be auto-selected.
     */
    public function translate(
        string $text,
        array $targets,
        ?string $sourceLanguage = null,
        ?LlmModel $model = null,
        array $options = []
    ): ?MultiTranslateResponseDto {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text to translate cannot be empty.');
        }
        if (empty($targets)) {
            throw new InvalidArgumentException('Targets array cannot be empty.');
        }

        try {
            // 1. Normalize target languages
            $targetCodes = $this->normalizeTargets($targets);
            if (empty($targetCodes)) {
                // This shouldn't happen if normalizeTargets throws, but as a safeguard.
                throw new InvalidArgumentException('No valid target languages provided after normalization.');
            }

            // 2. Detect source language if needed
            $finalSourceLanguage = $sourceLanguage;
            if (! $finalSourceLanguage) {
                $finalSourceLanguage = $this->detectSourceLanguage($text);
                if (! $finalSourceLanguage) {
                    // Failed to detect, cannot proceed reliably
                    report('Failed to auto-detect source language for multi-translation.');

                    return null;
                }
                // Optional: Log the detected language?
                // info("Detected source language: {$finalSourceLanguage}");
            } else {
                // Optionally validate provided source language format (e.g., 2 letters)
                $finalSourceLanguage = strtolower(trim($sourceLanguage));
            }

            // 3. Select Model
            $selectedModel = $this->selectModel($model);
            $provider = $this->laravelAi->provider($selectedModel->provider);

            // 4. Build Prompt
            // The helper builds the system message. We need the user prompt too.
            $systemMessage = $this->buildTranslationPrompt($text, $finalSourceLanguage, $targetCodes);
            $userPrompt = "Please perform the translation task as instructed on the following text:\n\n\"\"\"\n{$text}\n\"\"\"";

            // 5. Make LLM Call
            $request = new ChatRequest([
                'prompt' => $userPrompt,
                'systemMessage' => $systemMessage,
                'jsonMode' => true,
                'options' => array_merge($options, ['model' => $selectedModel->model_id]),
            ]);

            $response = $provider->chat($request);

            // 6. Process Response
            if (! $response->isJson || ! is_array($response->decodedJsonContent)) {
                report('Multi-translation failed: LLM did not return valid JSON. Response: '.$response->content);

                return null;
            }

            $responseData = $response->decodedJsonContent;

            // Validate basic structure
            if (! isset($responseData['source_language']) || ! isset($responseData['translations']) || ! is_array($responseData['translations'])) {
                report('Multi-translation failed: LLM returned JSON with unexpected structure. Data: '.json_encode($responseData));

                return null;
            }

            // Ensure source language matches (or trust LLM? For now, let's check)
            $returnedSourceLang = strtolower(trim($responseData['source_language']));
            if ($returnedSourceLang !== $finalSourceLanguage) {
                report("Multi-translation warning: LLM returned source language '{$returnedSourceLang}' which differs from expected/detected '{$finalSourceLanguage}'. Trusting LLM result.");
                // Use the one returned by LLM for the DTO
                $finalSourceLanguage = $returnedSourceLang;
            }

            // Build the DTO, ensuring all requested targets are present (with null if missing/failed)
            $finalTranslations = [];
            foreach ($targetCodes as $code) {
                $finalTranslations[$code] = $responseData['translations'][$code] ?? null;
                if (is_null($finalTranslations[$code])) {
                    report("Multi-translation warning: LLM did not provide translation for target '{$code}'.");
                }
            }

            return new MultiTranslateResponseDto([
                'sourceLanguage' => $finalSourceLanguage,
                'translations' => $finalTranslations,
            ]);
        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors
            throw $e;
        } catch (\Exception $e) {
            report($e); // Log other unexpected errors

            return null;
        }
    }

    // --- Private Helper Methods ---

    /**
     * Validates the target languages provided in the input array.
     * Converts valid string ISO codes or Language enum cases into an array of unique, normalized ISO codes.
     *
     * @param  array<int, Language|string>  $targets  Raw input array of targets.
     * @return array<int, string> Array of unique, valid ISO language codes.
     *
     * @throws InvalidArgumentException If any target is invalid.
     */
    private function normalizeTargets(array $targets): array
    {
        $normalized = [];
        foreach ($targets as $target) {
            if ($target instanceof Language) {
                $normalized[] = $target->code();
            } elseif (is_string($target) && Language::tryFromCode($target)) {
                $normalized[] = Language::tryFromCode($target)->code(); // Use consistent code casing
            } else {
                throw new InvalidArgumentException('Invalid target language provided: '.(is_string($target) ? $target : gettype($target)));
            }
        }

        return array_unique($normalized);
    }

    /**
     * Detects the source language of a text sample using a simple LLM query.
     *
     * @param  string  $text  The text to analyze (a sample will be extracted).
     * @return string|null The detected ISO 639-1 language code (lowercase), or null on failure or invalid response.
     */
    private function detectSourceLanguage(string $text): ?string
    {
        // Extract a sample (e.g., first 500 chars) to avoid sending huge text for detection
        $sample = mb_substr($text, 0, 500);

        // Corrected prompt construction
        $prompt = <<<PROMPT
Identify the ISO 639-1 language code (e.g., 'en', 'it', 'fr') for the following text. Respond ONLY with the two-letter code.

Text:
"""
{$sample}
"""

ISO Code:
PROMPT;

        try {
            $detectedCode = $this->laravelAi->ask(prompt: $prompt);
            $trimmedCode = trim(strtolower($detectedCode));

            // Basic validation: check if it looks like a 2-letter code
            if (preg_match('/^[a-z]{2}$/', $trimmedCode)) {
                return $trimmedCode;
            }
            // Optional: Further validation against known codes if needed

            report("LLM returned invalid code '{$detectedCode}' for language detection.");

            return null;
        } catch (\Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Selects an appropriate LLM model for the multi-translation task.
     *
     * If a model is provided, it validates its JSON mode support.
     * If no model is provided, it attempts to find the best available flagship model
     * that supports JSON mode, falling back to any JSON-supporting model.
     *
     * @param  LlmModel|null  $providedModel  The user-provided model preference.
     * @return LlmModel The selected LlmModel instance.
     *
     * @throws InvalidArgumentException If the provided model doesn't support JSON, or if no suitable model is found/configured.
     */
    private function selectModel(?LlmModel $providedModel): LlmModel
    {
        if ($providedModel) {
            if (! $providedModel->json_mode) {
                // Maybe just warn? Or let the API call fail?
                // For now, let's be strict.
                throw new InvalidArgumentException("Provided model '{$providedModel->model_id}' does not support JSON mode, which is required for multi-translation.");
            }

            return $providedModel;
        }

        // Find a suitable model from the LlmModel list
        // Prioritize flagship models that support JSON
        $suitableModel = LlmModel::where('json_mode', true)
            ->where('flagship', true)
            ->orderBy('context_window', 'desc') // Prefer larger context
            ->first();

        if (! $suitableModel) {
            // Fallback: any model supporting JSON mode
            $suitableModel = LlmModel::where('json_mode', true)
                ->orderBy('context_window', 'desc')
                ->first();
        }

        if (! $suitableModel) {
            throw new InvalidArgumentException('No suitable LLM model configured/found that supports JSON mode required for multi-translation.');
        }

        // Log which model was auto-selected? Maybe not necessary.
        // info("Auto-selected model for multi-translation: {$suitableModel->model_id}");

        return $suitableModel;
    }

    /**
     * Builds the system message prompt for the LLM multi-translation task.
     *
     * This message instructs the LLM on the task, required JSON output format,
     * source language, and target languages.
     *
     * @param  string  $text  The original text (used only for context if needed, not directly in system message).
     * @param  string  $sourceLanguageCode  The confirmed/detected source language ISO code.
     * @param  array<int, string>  $targetLanguageCodes  Array of normalized target language ISO codes.
     * @return string The formatted system message string.
     */
    private function buildTranslationPrompt(string $text, string $sourceLanguageCode, array $targetLanguageCodes): string
    {
        $targetList = '- '.implode("\n- ", $targetLanguageCodes);
        $jsonExample = json_encode(['source_language' => $sourceLanguageCode, 'translations' => array_fill_keys($targetLanguageCodes, '[Translated Text Here]')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // System message giving clear instructions
        $systemMessage = <<<PROMPT
You are an expert translation engine. Your task is to perform the following steps:
1. Confirm the source language of the provided text is '{$sourceLanguageCode}'.
2. Translate the text accurately into *all* of the following target languages: {$targetList}
3. Format your entire response *exclusively* as a single, valid JSON object. Do not include any other text, explanations, or markdown formatting before or after the JSON.
4. The JSON object must have exactly two keys: 'source_language' (string, the confirmed source language code) and 'translations' (object).
5. The 'translations' object must map each target language code (e.g., "it", "de") to its corresponding translated text (string). If a translation for a specific target language fails for some reason, use a null value for that language key.

Example JSON structure:
```json
{$jsonExample}
```
PROMPT;

        // Main user prompt containing the text
        $userPrompt = <<<PROMPT
Please perform the translation task as instructed on the following text:

"""
{$text}
"""
PROMPT;

        // Combine system and user prompt appropriately (this might need adjustment based on how LaravelAiManager handles system messages implicitly)
        // For now, let's assume we need to pass both to chat() or askWithSystem()
        // We'll refine this when calling the actual LLM method.
        // Returning concatenated for now, might need separate return or structure later.
        // UPDATE: We will use chat() which takes systemMessage and prompt separately.
        // So this function should return the system message? Or the user prompt?
        // Let's make it return an array [systemMessage, userPrompt]

        // Let's rethink. The service method should orchestrate. Helpers prepare data.
        // This function just prepares the SYSTEM message string.
        return $systemMessage;
    }
}
