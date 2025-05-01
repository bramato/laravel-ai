<?php

namespace Bramato\LaravelAi\Tests\Feature\Services;

use Bramato\LaravelAi\LaravelAiManager;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Services\SummarizationService;
use Bramato\LaravelAi\Tests\TestCase;
use Mockery\MockInterface;

class SummarizationServiceTest extends TestCase
{
    /** @test */
    public function it_can_summarize_text(): void
    {
        $text = 'This is a long text about Laravel and its components that needs summarization.';
        $expectedSummary = 'Laravel text summary.';

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedSummary, $text) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->withArgs(function (string $prompt, string $systemMessage, ?LlmModel $model, array $options) use ($text) {
                    $this->assertStringContainsString($text, $prompt);
                    $this->assertStringContainsString('Summarize the following text', $systemMessage);
                    // Check default constraints are not added
                    $this->assertStringNotContainsString('format of:', $systemMessage);
                    $this->assertStringNotContainsString('length of approximately', $systemMessage);

                    return true;
                })
                ->andReturn($expectedSummary);
        });

        $service = $this->app->make(SummarizationService::class);
        $result = $service->summarize($text);

        $this->assertEquals($expectedSummary, $result);
    }

    /** @test */
    public function it_can_summarize_text_with_format_and_length(): void
    {
        $text = 'Another long text.';
        $format = 'bullet points';
        $length = 50;
        $expectedSummary = "- Point 1\n- Point 2";

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedSummary, $text, $format, $length) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->withArgs(function (string $prompt, string $systemMessage, ?LlmModel $model, array $options) use ($text, $format, $length) {
                    $this->assertStringContainsString($text, $prompt);
                    $this->assertStringContainsString("format of: {$format}", $systemMessage);
                    $this->assertStringContainsString("length of approximately {$length}", $systemMessage);

                    return true;
                })
                ->andReturn($expectedSummary);
        });

        $service = $this->app->make(SummarizationService::class);
        $result = $service->summarize($text, $format, $length);

        $this->assertEquals($expectedSummary, $result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_empty_string(): void
    {
        $text = 'Some text.';

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('askWithSystem')->once()->andReturn('   '); // Empty or whitespace
        });

        $service = $this->app->make(SummarizationService::class);
        $result = $service->summarize($text);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_call_fails(): void
    {
        // Mock report helper if not already mocked globally
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op for test */ } ');
        }

        $text = 'Some text.';

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('askWithSystem')->once()->andThrow(new \Exception('API Error'));
        });

        $service = $this->app->make(SummarizationService::class);
        $result = $service->summarize($text);

        $this->assertNull($result);
    }

    /** @test */
    public function it_throws_exception_if_text_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Text to summarize cannot be empty.');

        $service = $this->app->make(SummarizationService::class);
        $service->summarize('  '); // Empty or whitespace
    }

    /** @test */
    public function it_uses_specific_model_and_options(): void
    {
        $text = 'Text to summarize with specific model.';
        $expectedSummary = 'Specific summary.';
        $model = new LlmModel(['provider' => 'openai', 'model_id' => 'gpt-4o-mini']);
        $options = ['temperature' => 0.5];

        $mockManager = $this->mock(LaravelAiManager::class, function (MockInterface $mock) use ($expectedSummary, $model, $options) {
            $mock->shouldReceive('askWithSystem')
                ->once()
                ->withArgs(function (string $prompt, string $systemMessage, ?LlmModel $passedModel, array $passedOptions) use ($model, $options) {
                    $this->assertSame($model, $passedModel);
                    $this->assertEquals($options, $passedOptions);

                    return true;
                })
                ->andReturn($expectedSummary);
        });

        $service = $this->app->make(SummarizationService::class);
        $result = $service->summarize($text, null, null, $model, $options);

        $this->assertEquals($expectedSummary, $result);
    }
}
