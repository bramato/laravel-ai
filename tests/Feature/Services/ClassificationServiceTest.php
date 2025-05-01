<?php

namespace Bramato\LaravelAi\Tests\Feature\Services;

use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Services\ClassificationService;
use Bramato\LaravelAi\Tests\TestCase; // Ensure TestCase is used for Laravel context
use Mockery\MockInterface;

class ClassificationServiceTest extends TestCase
{
    // Helper to mock ChatResponse
    private function mockChatResponse(string $content): ChatResponse
    {
        return new ChatResponse([
            'id' => 'chat-test-123',
            'model' => 'test-model',
            'content' => $content,
            'finishReason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'isJson' => false,
            'decodedJsonContent' => null,
            'rawResponse' => ['mock' => true],
        ]);
    }

    /** @test */
    public function it_can_classify_text_into_a_valid_category(): void
    {
        $categories = ['Support', 'Sales', 'Technical'];
        $text = 'My login is not working.';
        $expectedCategory = 'Support';

        // Mock the LaravelAiManager specifically for the askWithSystem method
        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedCategory, $text, $categories) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->withArgs(function (string $prompt, string $systemMessage, ?LlmModel $model, array $options) use ($text, $categories) {
                    // Basic checks on prompt/system message content
                    $this->assertStringContainsString($text, $prompt);
                    $this->assertStringContainsString('text classification assistant', $systemMessage);
                    foreach ($categories as $category) {
                        $this->assertStringContainsString("- {$category}", $systemMessage);
                    }

                    return true;
                })
                ->andReturn($expectedCategory);
        });

        // Resolve the service from the container (which will get the mocked Manager)
        $service = $this->app->make(ClassificationService::class);

        $result = $service->classify($text, $categories);

        $this->assertEquals($expectedCategory, $result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_invalid_category(): void
    {
        $categories = ['Support', 'Sales', 'Technical'];
        $text = 'My login is not working.';
        $invalidResponse = 'General Inquiry'; // Not in the list

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($invalidResponse) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->andReturn($invalidResponse);
        });

        $service = $this->app->make(ClassificationService::class);
        $result = $service->classify($text, $categories);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_call_fails(): void
    {
        // Mock report helper if not already mocked globally
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op for test */ } ');
        }

        $categories = ['Support', 'Sales', 'Technical'];
        $text = 'My login is not working.';

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->andThrow(new \Exception('API Error'));
        });

        $service = $this->app->make(ClassificationService::class);
        $result = $service->classify($text, $categories);

        $this->assertNull($result);
    }

    /** @test */
    public function it_throws_exception_if_categories_are_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Categories array cannot be empty.');

        $service = $this->app->make(ClassificationService::class);
        $service->classify('Some text', []);
    }

    /** @test */
    public function it_throws_exception_if_categories_are_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Categories must be non-empty strings.');

        $service = $this->app->make(ClassificationService::class);
        $service->classify('Some text', ['Valid', '  ', 'Also Valid']); // Contains empty string
    }

    /** @test */
    public function it_uses_specific_model_and_options_when_provided(): void
    {
        $categories = ['A', 'B'];
        $text = 'Test';
        $expectedCategory = 'A';
        $model = new LlmModel(['provider' => 'openai', 'model_id' => 'gpt-4o-mini']);
        $options = ['temperature' => 0.1];

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedCategory, $model, $options) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->withArgs(function (string $prompt, string $systemMessage, ?LlmModel $passedModel, array $passedOptions) use ($model, $options) {
                    // Check if the correct model and options were passed through
                    $this->assertSame($model, $passedModel);
                    $this->assertEquals($options, $passedOptions);

                    return true;
                })
                ->andReturn($expectedCategory);
        });

        $service = $this->app->make(ClassificationService::class);
        $result = $service->classify($text, $categories, $model, $options);

        $this->assertEquals($expectedCategory, $result);
    }
}
