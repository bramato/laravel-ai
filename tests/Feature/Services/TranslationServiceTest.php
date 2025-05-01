<?php

namespace Bramato\LaravelAi\Tests\Feature\Services;

use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Services\TranslationService;
use Bramato\LaravelAi\Tests\TestCase;
use Mockery\MockInterface;

class TranslationServiceTest extends TestCase
{
    /** @test */
    public function it_can_translate_text(): void
    {
        $text = "Hello world";
        $targetLanguage = "French";
        $expectedTranslation = "Bonjour le monde";

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedTranslation, $text, $targetLanguage) {
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(function (string $prompt, ?LlmModel $model, array $options) use ($text, $targetLanguage) {
                    $this->assertStringContainsString($text, $prompt);
                    $this->assertStringContainsString("Translate the following text into {$targetLanguage}", $prompt);
                    $this->assertStringContainsString("auto-detect the source language", $prompt); // Default case
                    return true;
                })
                ->andReturn($expectedTranslation);
        });

        $service = $this->app->make(TranslationService::class);
        $result = $service->translate($text, $targetLanguage);

        $this->assertEquals($expectedTranslation, $result);
    }

    /** @test */
    public function it_can_translate_text_with_source_language(): void
    {
        $text = "Wie geht es dir?";
        $targetLanguage = "English";
        $sourceLanguage = "German";
        $expectedTranslation = "How are you?";

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedTranslation, $text, $targetLanguage, $sourceLanguage) {
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(function (string $prompt, ?LlmModel $model, array $options) use ($text, $targetLanguage, $sourceLanguage) {
                    $this->assertStringContainsString($text, $prompt);
                    $this->assertStringContainsString("Translate the following text into {$targetLanguage}", $prompt);
                    $this->assertStringContainsString("original text is in {$sourceLanguage}", $prompt);
                    return true;
                })
                ->andReturn($expectedTranslation);
        });

        $service = $this->app->make(TranslationService::class);
        $result = $service->translate($text, $targetLanguage, $sourceLanguage);

        $this->assertEquals($expectedTranslation, $result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_empty_string(): void
    {
        $text = "Translate me";
        $targetLanguage = "Italian";

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('ask')->once()->andReturn('   ');
        });

        $service = $this->app->make(TranslationService::class);
        $result = $service->translate($text, $targetLanguage);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_call_fails(): void
    {
        // Mock report helper if not already mocked globally
        if (!function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op for test */ } ');
        }

        $text = "Translate me";
        $targetLanguage = "Italian";

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('ask')->once()->andThrow(new \Exception('API Error'));
        });

        $service = $this->app->make(TranslationService::class);
        $result = $service->translate($text, $targetLanguage);

        $this->assertNull($result);
    }

    /** @test */
    public function it_throws_exception_if_text_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Text to translate cannot be empty.');

        $service = $this->app->make(TranslationService::class);
        $service->translate('  ', 'Italian');
    }

    /** @test */
    public function it_throws_exception_if_target_language_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target language cannot be empty.');

        $service = $this->app->make(TranslationService::class);
        $service->translate('Some text', '   ');
    }

    /** @test */
    public function it_uses_specific_model_and_options(): void
    {
        $text = "Translate model test";
        $targetLanguage = "Spanish";
        $expectedTranslation = "Prueba de modelo de traducción";
        $model = new LlmModel(['provider' => 'openai', 'model_id' => 'gpt-4o-mini']);
        $options = ['temperature' => 0.2];

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedTranslation, $model, $options) {
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(function (string $prompt, ?LlmModel $passedModel, array $passedOptions) use ($model, $options) {
                    $this->assertSame($model, $passedModel);
                    $this->assertEquals($options, $passedOptions);
                    return true;
                })
                ->andReturn($expectedTranslation);
        });

        $service = $this->app->make(TranslationService::class);
        $result = $service->translate($text, $targetLanguage, null, $model, $options);

        $this->assertEquals($expectedTranslation, $result);
    }
}
