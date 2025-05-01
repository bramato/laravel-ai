<?php

use Bramato\LaravelAi\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is \PHPUnit\Framework\TestCase. But you can change it
| if you want to introduce some features specific to your tests suite.
|
*/

// Applies the given test case to all tests in the 'Feature' directory.
// This ensures that the Laravel environment and our package are loaded for feature tests.
uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions.
| Pest provides a fluent API for writing assertions expectations. These expectations
| match the expectations available in PHPUnit. Standard PHPUnit assertions are supported.
|
| Learn more: https://pestphp.com/docs/expectations
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
| Learn more: https://pestphp.com/docs/helpers
|
*/

// --- Global Test Helper Functions ---

/**
 * Generates fake successful response data similar to OpenAI/DeepSeek.
 */
function getFakeSuccessResponseData(string $id = 'chatcmpl-123', string $model = 'test-model', string $content = 'Test content'): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion',
        'created' => time(),
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
        ],
    ];
}

/**
 * Generates fake error response data similar to OpenAI/DeepSeek.
 */
function getFakeErrorResponseData(string $message, string $type, string $code): array
{
    return [
        'error' => [
            'message' => $message,
            'type' => $type,
            'param' => null,
            'code' => $code,
        ],
    ];
}

/**
 * Generates fake successful response data for Gemini.
 */
function getFakeGeminiSuccessResponse(string $content = 'Gemini test content', string $finishReason = 'STOP', ?array $usage = null): array
{
    $response = [
        'candidates' => [
            [
                'content' => [
                    'parts' => [['text' => $content]],
                    'role' => 'model',
                ],
                'finishReason' => $finishReason,
                'index' => 0,
                'safetyRatings' => [
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'probability' => 'NEGLIGIBLE'],
                    // ... other categories
                ],
            ],
        ],
    ];
    if ($usage === null) {
        $usage = ['promptTokenCount' => 15, 'candidatesTokenCount' => 25, 'totalTokenCount' => 40];
    }
    $response['usageMetadata'] = $usage;

    return $response;
}

/**
 * Generates fake error response data for Gemini.
 */
function getFakeGeminiErrorResponse(int $code, string $message, string $status): array
{
    return [
        'error' => [
            'code' => $code,
            'message' => $message,
            'status' => $status,
        ],
    ];
}

/**
 * Generates fake successful response data for Claude.
 */
function getFakeClaudeSuccessResponse(
    string $id = 'msg_test_123',
    string $content = 'Claude test content.',
    string $stopReason = 'end_turn',
    ?array $usage = ['input_tokens' => 12, 'output_tokens' => 22]
): array {
    return [
        'id' => $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-test-model',
        'content' => [
            ['type' => 'text', 'text' => $content],
        ],
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => $usage,
    ];
}

/**
 * Generates fake error response data for Claude.
 */
function getFakeClaudeErrorResponse(string $type, string $message): array
{
    return [
        'type' => 'error',
        'error' => [
            'type' => $type,
            'message' => $message,
        ],
    ];
}

// function something(): string
// {
//     return 'helper';
// }
