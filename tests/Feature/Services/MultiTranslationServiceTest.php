<?php

namespace Bramato\LaravelAi\Tests\Feature\Services;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\DTOs\MultiTranslateResponseDto;
use Bramato\LaravelAi\Enums\Language;
use Bramato\LaravelAi\Exceptions\LlmApiException;
use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Services\MultiTranslationService;
use Bramato\LaravelAi\Tests\TestCase;
use Mockery\MockInterface;

class MultiTranslationServiceTest extends TestCase
{
    // Helper to mock ChatResponse for JSON
    private function mockJsonResponse(array $data, bool $isJson = true): ChatResponse
    {
        $content = $isJson ? json_encode($data) : 'Invalid JSON response';

        return new ChatResponse([
            'id' => 'chat-multi-test-123',
            'model' => 'gpt-4-turbo-test', // Assume a capable model
            'content' => $content,
            'finishReason' => 'stop',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            'isJson' => $isJson,
            'decodedJsonContent' => $isJson ? $data : null,
            'rawResponse' => ['mock' => true],
        ]);
    }

    /** @test */
    public function it_can_translate_text_into_multiple_languages(): void
    {
        $text = 'Hello';
        $targets = [Language::ITALIAN, 'de']; // Mix of enum and string
        $expectedSource = 'en';
        $mockJsonResponseData = [
            'source_language' => $expectedSource,
            'translations' => [
                'it' => 'Ciao',
                'de' => 'Hallo',
            ],
        ];

        // Mock the specific client that will be resolved via the manager
        // We need to mock the specific provider client expected to be used (e.g., openai)
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) use ($mockJsonResponseData) {
            // Mock the chat call expected for the main translation
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) {
                    // Check if jsonMode is true and prompt looks right
                    $this->assertTrue($request->jsonMode);
                    $this->assertStringContainsString('Hello', $request->prompt);
                    $this->assertStringContainsString('expert translation engine', $request->systemMessage);
                    $this->assertStringContainsString('target languages: - it\n- de', $request->systemMessage); // Check normalized targets

                    return true;
                })
                ->andReturn($this->mockJsonResponse($mockJsonResponseData));
        });

        // Mock the LaravelAiManager for the source language detection call (ask)
        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) use ($mockClient, $expectedSource) {
            // Mock the 'ask' call for language detection
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(function (string $prompt) {
                    $this->assertStringContainsString('Identify the ISO 639-1', $prompt);
                    $this->assertStringContainsString('Hello', $prompt);

                    return true;
                })
                ->andReturn($expectedSource);

            // Ensure provider() returns our mocked client when the selected model's provider is requested
            // We need to find which model selectModel() would pick
            $jsonModel = LlmModel::where('json_mode', true)->orderBy('context_window', 'desc')->first();
            $this->assertNotNull($jsonModel, 'No JSON supporting model found in test setup for mocking provider.');
            $mock->shouldReceive('provider')->with($jsonModel->provider)->andReturn($mockClient);
        });

        // Resolve the service (it will get the partially mocked Manager)
        $service = $this->app->make(MultiTranslationService::class);

        $result = $service->translate($text, $targets); // Auto-detect source

        $this->assertInstanceOf(MultiTranslateResponseDto::class, $result);
        $this->assertEquals($expectedSource, $result->sourceLanguage);
        $this->assertCount(2, $result->translations);
        $this->assertEquals('Ciao', $result->translations['it']);
        $this->assertEquals('Hallo', $result->translations['de']);
    }

    /** @test */
    public function it_uses_provided_source_language_and_model(): void
    {
        $text = 'Bonjour';
        $targets = [Language::ENGLISH_US];
        $sourceLanguage = 'fr';
        $mockJsonResponseData = [
            'source_language' => $sourceLanguage,
            'translations' => [
                'en_US' => 'Hello',
            ],
        ];
        $model = LlmModel::where('model_id', 'gpt-4o-test')->first(); // Assume this exists and supports JSON
        $this->assertNotNull($model, "Test model gpt-4o-test not found or doesn't support JSON.");
        $this->assertTrue($model->json_mode, 'Test model gpt-4o-test must support JSON mode.');

        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) use ($mockJsonResponseData, $model, $sourceLanguage) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) use ($model, $sourceLanguage) {
                    $this->assertTrue($request->jsonMode);
                    $this->assertEquals($model->model_id, $request->options['model'] ?? null);
                    $this->assertStringContainsString($sourceLanguage, $request->systemMessage);
                    $this->assertStringContainsString('en_US', $request->systemMessage);

                    return true;
                })
                ->andReturn($this->mockJsonResponse($mockJsonResponseData));
        });

        // We only need to mock the provider call for the specific model
        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) use ($mockClient, $model) {
            // Ensure provider() returns our mocked client
            $mock->shouldReceive('provider')->with($model->provider)->andReturn($mockClient);
            // No 'ask' should be called for detection
            $mock->shouldNotReceive('ask');
        });

        $service = $this->app->make(MultiTranslationService::class);
        $result = $service->translate($text, $targets, $sourceLanguage, $model);

        $this->assertInstanceOf(MultiTranslateResponseDto::class, $result);
        $this->assertEquals($sourceLanguage, $result->sourceLanguage);
        $this->assertEquals('Hello', $result->translations['en_US']);
    }

    /** @test */
    public function it_returns_null_if_language_detection_fails(): void
    {
        $text = 'Hola';
        $targets = ['it'];

        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('ask')->once()->andReturn('xx'); // Invalid code
            $mock->shouldNotReceive('provider'); // Should not attempt main call
            $mock->shouldNotReceive('chat');
        });

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($message) { /* No-op */ }');
        }

        $service = $this->app->make(MultiTranslationService::class);
        $result = $service->translate($text, $targets); // Auto-detect

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_main_llm_call_fails(): void
    {
        $text = 'Test';
        $targets = ['fr'];
        $sourceLanguage = 'en';

        $model = LlmModel::where('json_mode', true)->first();
        $this->assertNotNull($model);

        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new LlmApiException('LLM Error'));
        });

        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) use ($mockClient, $model) {
            $mock->shouldReceive('provider')->with($model->provider)->andReturn($mockClient);
            $mock->shouldNotReceive('ask');
        });

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op */ }');
        }

        $service = $this->app->make(MultiTranslationService::class);
        $result = $service->translate($text, $targets, $sourceLanguage, $model);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_invalid_json(): void
    {
        $text = 'Test';
        $targets = ['fr'];
        $sourceLanguage = 'en';

        $model = LlmModel::where('json_mode', true)->first();
        $this->assertNotNull($model);

        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            // Return invalid JSON
            $mock->shouldReceive('chat')->once()->andReturn($this->mockJsonResponse([], false));
        });

        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) use ($mockClient, $model) {
            $mock->shouldReceive('provider')->with($model->provider)->andReturn($mockClient);
            $mock->shouldNotReceive('ask');
        });

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($message) { /* No-op */ }');
        }

        $service = $this->app->make(MultiTranslationService::class);
        $result = $service->translate($text, $targets, $sourceLanguage, $model);

        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_missing_translations_in_response(): void
    {
        $text = 'Test';
        $targets = ['fr', 'de', 'it']; // Request 3
        $sourceLanguage = 'en';
        $mockJsonResponseData = [
            'source_language' => $sourceLanguage,
            'translations' => [
                'fr' => 'Le Test', // Provide 2
                'de' => 'Der Test',
                // 'it' is missing
            ],
        ];
        $model = LlmModel::where('json_mode', true)->first();
        $this->assertNotNull($model);

        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) use ($mockJsonResponseData) {
            $mock->shouldReceive('chat')->once()->andReturn($this->mockJsonResponse($mockJsonResponseData));
        });

        $mockManager = $this->partialMock(LaravelAiManager::class, function (MockInterface $mock) use ($mockClient, $model) {
            $mock->shouldReceive('provider')->with($model->provider)->andReturn($mockClient);
            $mock->shouldNotReceive('ask');
        });

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($message) { /* No-op */ }');
        }

        $service = $this->app->make(MultiTranslationService::class);
        $result = $service->translate($text, $targets, $sourceLanguage, $model);

        $this->assertInstanceOf(MultiTranslateResponseDto::class, $result);
        $this->assertCount(3, $result->translations); // Should have keys for all requested targets
        $this->assertEquals('Le Test', $result->translations['fr']);
        $this->assertEquals('Der Test', $result->translations['de']);
        $this->assertNull($result->translations['it']); // Missing one should be null
    }

    /** @test */
    public function it_throws_if_no_suitable_model_is_found(): void
    {
        $text = 'Test';
        $targets = ['it'];

        // Temporarily remove JSON support from models for this test
        $originalModels = LlmModel::getRows();
        $modifiedRows = array_map(function ($row) {
            $row['json_mode'] = false;

            return $row;
        }, $originalModels);
        LlmModel::setRows($modifiedRows);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No suitable LLM model configured/found that supports JSON mode');

        $service = $this->app->make(MultiTranslationService::class);

        try {
            $service->translate($text, $targets);
        } finally {
            // Restore original models
            LlmModel::setRows($originalModels);
        }
    }

    /** @test */
    public function it_throws_if_invalid_target_language_is_provided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid target language provided');

        $service = $this->app->make(MultiTranslationService::class);
        $service->translate('Test', ['it', 'invalid-code']);
    }
}
