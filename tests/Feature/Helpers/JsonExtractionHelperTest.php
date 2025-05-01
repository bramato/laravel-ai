<?php

namespace Bramato\LaravelAi\Tests\Feature\Helpers;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Tests\TestCase;
use Mockery\MockInterface;

class JsonExtractionHelperTest extends TestCase
{
    private function mockClientResponse(string $content, bool $isJson = false, ?string $finishReason = 'stop'): ChatResponse
    {
        $modelId = 'test-model'; // Consistent model ID
        $responseId = 'test-resp-id-123'; // Consistent response ID
        return new ChatResponse([
            'content' => $content,
            'finishReason' => $finishReason ?? 'unknown', // Ensure non-null
            'isJson' => $isJson,
            'decodedJsonContent' => $isJson ? json_decode($content, true) : null,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30], // Example usage
            'providerId' => 'test-provider', // Example providerId
            'modelId' => $modelId, // Keep this if ChatResponse expects it separately
            'model' => $modelId, // Property from DTO definition
            'id' => $responseId, // Add the missing 'id' property
        ]);
    }

    /** @test */
    public function it_can_extract_json_using_default_provider(): void
    {
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) {
                    $this->assertTrue($request->jsonMode);
                    $this->assertStringContainsString('Extract user details', $request->prompt);
                    $this->assertStringContainsString('Some text about John Doe', $request->prompt);
                    return true;
                })
                ->andReturn($this->mockClientResponse('{"name": "John Doe", "age": 30}', true));
        });

        // Bind the mock client to the default provider's binding key
        $defaultProvider = config('laravel-ai.default');
        $this->app->instance("laravel-ai.client.{$defaultProvider}", $mockClient);

        $instruction = 'Extract user details';
        $text = 'Some text about John Doe, 30 years old.';
        $expectedJson = ['name' => 'John Doe', 'age' => 30];

        $result = LaravelAi::extractJson($instruction, $text);

        $this->assertEquals($expectedJson, $result);
    }

    /** @test */
    public function it_can_extract_json_using_specific_model(): void
    {
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) {
                    $this->assertTrue($request->jsonMode);
                    $this->assertEquals('gemini-pro', $request->options['model'] ?? null);
                    $this->assertStringContainsString('Extract item info', $request->prompt);
                    $this->assertStringContainsString('The item is a Widget, price 99.99', $request->prompt);
                    return true;
                })
                ->andReturn($this->mockClientResponse('{"item": "Widget", "price": 99.99}', true));
        });

        // Create a mock model
        $model = new LlmModel(['provider' => 'gemini', 'model_id' => 'gemini-pro']);

        // Bind the mock client to the specific provider's binding key
        $this->app->instance('laravel-ai.client.gemini', $mockClient);

        $instruction = 'Extract item info';
        $text = 'The item is a Widget, price 99.99';
        $expectedJson = ['item' => 'Widget', 'price' => 99.99];

        $result = LaravelAi::extractJson($instruction, $text, $model);

        $this->assertEquals($expectedJson, $result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_invalid_json(): void
    {
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn(ChatRequest $request) => $request->jsonMode)
                ->andReturn($this->mockClientResponse('This is not json {', false)); // Not JSON
        });

        $defaultProvider = config('laravel-ai.default');
        $this->app->instance("laravel-ai.client.{$defaultProvider}", $mockClient);

        $result = LaravelAi::extractJson('Extract anything', 'Some text');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_non_object_json(): void
    {
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn(ChatRequest $request) => $request->jsonMode)
                ->andReturn($this->mockClientResponse('"just a string"', true)); // Valid JSON, but not array/object
        });

        $defaultProvider = config('laravel-ai.default');
        $this->app->instance("laravel-ai.client.{$defaultProvider}", $mockClient);

        $result = LaravelAi::extractJson('Extract anything', 'Some text');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_returns_empty_content(): void
    {
        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn(ChatRequest $request) => $request->jsonMode)
                ->andReturn($this->mockClientResponse('', false)); // Empty content
        });

        $defaultProvider = config('laravel-ai.default');
        $this->app->instance("laravel-ai.client.{$defaultProvider}", $mockClient);

        $result = LaravelAi::extractJson('Extract anything', 'Some text');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_if_llm_call_fails(): void
    {
        // Mock report helper if not already mocked globally
        if (!function_exists('Bramato\LaravelAi\report')) { // Check namespaced function
            eval('namespace Bramato\LaravelAi; function report($exception) { /* No-op for test */ } ');
        }

        $mockClient = $this->mock(LlmClientInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andThrow(new \Exception('API Error'));
        });

        $defaultProvider = config('laravel-ai.default');
        $this->app->instance("laravel-ai.client.{$defaultProvider}", $mockClient);

        $result = LaravelAi::extractJson('Extract anything', 'Some text');

        $this->assertNull($result);
    }
}
