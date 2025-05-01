<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
// Assuming we might test exceptions later
use Bramato\LaravelAi\Facades\LaravelAi; // Keep facade for potential future use, but avoid mocking it directly if possible
use Bramato\LaravelAi\LaravelAiServiceProvider;
use Bramato\LaravelAi\Models\LlmModel;
use Bramato\LaravelAi\Services\ChatService;
use Illuminate\Support\Facades\Http; // For potential future HTTP mocking if needed directly
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

class ChatServiceTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        // Provide the package's service provider and facade alias
        return [
            LaravelAiServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'LaravelAi' => LaravelAi::class,
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to mock the default LlmClientInterface binding.
     *
     * @param  string|null  $initialExpectedContent  Optional initial content for the first call.
     */
    private function mockLlmClient(?string $initialExpectedContent = 'Mocked response content.'): MockInterface
    {
        // Mock the default interface binding once per test setup
        $mock = $this->instance(LlmClientInterface::class, Mockery::mock(LlmClientInterface::class));

        // Set initial expectation if provided
        if ($initialExpectedContent !== null) {
            $mock->shouldReceive('chat')
                ->once() // Expect one call initially
                ->andReturnUsing(function (ChatRequest $request) use ($initialExpectedContent) {
                    return new ChatResponse([
                        'id' => 'chatcmpl-mockid-default-1',
                        'model' => $request->options['model'] ?? 'default-mock-model',
                        'content' => $initialExpectedContent,
                        'finishReason' => 'stop',
                        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                        'isJson' => $request->jsonMode,
                        'decodedJsonContent' => $request->jsonMode ? ['mock_key' => 'mock_value'] : null,
                        'rawResponse' => ['provider_used' => 'default', 'call' => 1],
                    ]);
                });
        }

        return $mock; // Return the mock instance for potentially adding more expectations
    }

    /**
     * Helper function to create a complete ChatResponse array for mocking.
     */
    private function createMockResponseData(string $content, string $model = 'mock-model', bool $isJson = false): array
    {
        return [
            'id' => 'chatcmpl-'.uniqid(),
            'model' => $model,
            'content' => $content,
            'finishReason' => 'stop',
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            'isJson' => $isJson,
            'decodedJsonContent' => $isJson ? ['data' => 'mocked'] : null,
            'rawResponse' => ['mocked_data' => true],
        ];
    }

    /**
     * Override application setup to register provider aliases needed for mocking.
     *
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app)
    {
        // Define aliases for specific provider instances that we might mock.
        // These aliases match a potential internal structure the Facade might use.
        // Adjust if the actual resolution mechanism is different.
        $app->alias(LlmClientInterface::class, 'laravel-ai');
        // Simulating potential internal aliases for providers
        $app->alias(LlmClientInterface::class, 'laravel-ai.provider.openai');
        $app->alias(LlmClientInterface::class, 'laravel-ai.provider.anthropic');
        $app->alias(LlmClientInterface::class, 'laravel-ai.provider.gemini');
        $app->alias(LlmClientInterface::class, 'laravel-ai.provider.deepseek');

        // Set a default provider config for tests if needed
        $app['config']->set('laravel-ai.default', 'openai');
        $app['config']->set('laravel-ai.providers.openai', [
            'api_key' => 'test-key',
            'model' => 'gpt-test',
        ]);
        $app['config']->set('laravel-ai.providers.anthropic', [
            'api_key' => 'test-key-anthropic',
            'model' => 'claude-test',
            'options' => ['version' => '2023-06-01'], // Ensure required options exist for mocked providers
        ]);
    }

    /** @test */
    public function it_can_be_created_and_get_a_response()
    {
        $this->mockLlmClient('Hello there!');

        $chat = ChatService::create(initialPrompt: 'Hello');
        $response = $chat->getResponse();

        $this->assertInstanceOf(ChatResponse::class, $response);
        $this->assertEquals('Hello there!', $response->content);
        $this->assertCount(2, $chat->getHistory()); // Initial prompt + assistant response
        $this->assertEquals('user', $chat->getHistory()[0]['role']);
        $this->assertEquals('Hello', $chat->getHistory()[0]['content']);
        $this->assertEquals('assistant', $chat->getHistory()[1]['role']);
        $this->assertEquals('Hello there!', $chat->getHistory()[1]['content']);
    }

    /** @test */
    public function it_uses_system_message()
    {
        $clientMock = $this->mockLlmClient();

        ChatService::create(initialPrompt: 'Translate', systemMessage: 'Be funny')->getResponse();

        // Verify the ChatRequest passed to the mock client had the system message
        $clientMock->shouldHaveReceived('chat')->withArgs(function (ChatRequest $request) {
            return $request->systemMessage === 'Be funny';
        });
    }

    /** @test */
    public function it_adds_messages_and_maintains_history()
    {
        $clientMock = $this->mockLlmClient('Response 1');

        $chat = ChatService::create(initialPrompt: 'First message');
        $response1 = $chat->getResponse();

        // Add expectation for the *second* call on the *same* mock instance
        $clientMock->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function (ChatRequest $request) {
                // Ensure history is correct for the second call
                $this->assertCount(2, $request->history);
                $this->assertEquals('user', $request->history[0]['role']);
                $this->assertEquals('First message', $request->history[0]['content']);
                $this->assertEquals('assistant', $request->history[1]['role']);
                $this->assertEquals('Response 1', $request->history[1]['content']);
                $this->assertEquals('Second message', $request->prompt);

                return new ChatResponse($this->createMockResponseData('Response 2'));
            });

        $chat->addMessage('user', 'Second message');
        $response2 = $chat->getResponse();

        $this->assertCount(4, $chat->getHistory()); // user, assistant, user, assistant
        $this->assertEquals('First message', $chat->getHistory()[0]['content']);
        $this->assertEquals('Response 1', $chat->getHistory()[1]['content']);
        $this->assertEquals('Second message', $chat->getHistory()[2]['content']);
        $this->assertEquals('Response 2', $chat->getHistory()[3]['content']);
    }

    /** @test */
    public function it_uses_specific_llm_model_if_provided()
    {
        $specificClientMock = Mockery::mock(LlmClientInterface::class);
        LaravelAi::shouldReceive('provider')
            ->with('anthropic')
            ->andReturn($specificClientMock);

        $claudeModelId = 'claude-3-opus-20240229';
        $specificClientMock->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) use ($claudeModelId) {
                return isset($request->options['model']) && $request->options['model'] === $claudeModelId;
            })
            // Use helper to return complete response data
            ->andReturn(new ChatResponse($this->createMockResponseData('Claude says hi', $claudeModelId)));

        $claudeModel = LlmModel::where('model_id', $claudeModelId)->first();
        $this->assertNotNull($claudeModel, 'Claude model not found for test setup.');

        $chat = ChatService::create(initialPrompt: 'Hello Claude', llmModel: $claudeModel);
        $response = $chat->getResponse();

        $this->assertEquals('Claude says hi', $response->content);
        $this->assertEquals($claudeModelId, $response->model);
    }

    /** @test */
    public function it_handles_json_data_true()
    {
        $clientMock = $this->mockLlmClient();

        $chat = ChatService::create(initialPrompt: 'Give me JSON', jsonData: true);
        $response = $chat->getResponse();

        $clientMock->shouldHaveReceived('chat')->withArgs(function (ChatRequest $request) {
            return $request->jsonMode === true;
        });
        $this->assertTrue($response->isJson);
        $this->assertNotNull($response->decodedJsonContent);
    }

    /** @test */
    public function it_handles_json_data_array()
    {
        $clientMock = $this->mockLlmClient();
        $schema = ['name' => 'string', 'age' => 'integer'];
        $expectedPromptSuffix = "\n\nPlease provide the response strictly in JSON format matching the following structure:\n```json\n{\n    \"name\": \"string\",\n    \"age\": \"integer\"\n}\n```";

        $chat = ChatService::create(initialPrompt: 'Extract info', jsonData: $schema);
        $response = $chat->getResponse();

        $this->assertEquals('Extract info'.$expectedPromptSuffix, $chat->getHistory()[0]['content']);
        $clientMock->shouldHaveReceived('chat')->withArgs(function (ChatRequest $request) use ($expectedPromptSuffix) {
            return $request->jsonMode === true && str_ends_with($request->prompt, $expectedPromptSuffix);
        });
        $this->assertTrue($response->isJson);
    }

    /** @test */
    public function it_handles_json_data_string()
    {
        $clientMock = $this->mockLlmClient();
        $schemaString = '{"user_id": "number", "status": "string"}';
        $expectedPromptSuffix = "\n\nPlease provide the response strictly in JSON format matching the following structure:\n```json\n{$schemaString}\n```";

        $chat = ChatService::create(initialPrompt: 'Get status', jsonData: $schemaString);
        $response = $chat->getResponse();

        $this->assertEquals('Get status'.$expectedPromptSuffix, $chat->getHistory()[0]['content']);
        $clientMock->shouldHaveReceived('chat')->withArgs(function (ChatRequest $request) use ($expectedPromptSuffix) {
            return $request->jsonMode === true && str_ends_with($request->prompt, $expectedPromptSuffix);
        });
        $this->assertTrue($response->isJson);
    }

    /** @test */
    public function it_throws_exception_for_invalid_json_data_string()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The provided jsonData string is not valid JSON.');

        ChatService::create(initialPrompt: 'Test', jsonData: '{"invalid json');
    }

    /** @test */
    public function it_uses_helper_methods_correctly()
    {
        $claudeModel = LlmModel::where('model_id', 'claude-3-haiku-20240307')->first();
        $this->assertNotNull($claudeModel, 'Haiku model not found for test setup.');

        $specificClientMock = Mockery::mock(LlmClientInterface::class);
        LaravelAi::shouldReceive('provider')
            ->with('anthropic')
            ->andReturn($specificClientMock);

        $haikuModelId = $claudeModel->model_id;
        $specificClientMock->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) use ($haikuModelId) {
                return $request->options['model'] === $haikuModelId
                    && isset($request->options['temperature'])
                    && $request->options['temperature'] === 0.9;
            })
            // Use helper to return complete response data
            ->andReturn(new ChatResponse($this->createMockResponseData('Haiku response', $haikuModelId)));

        $chat = ChatService::create(initialPrompt: 'Initial');
        $chat->setProvider('anthropic');
        $chat->setModel($haikuModelId);
        $chat->setOptions(['temperature' => 0.9], true);

        $response = $chat->getResponse();

        $this->assertEquals('Haiku response', $response->content);
        $this->assertEquals($haikuModelId, $response->model);

        $history = $chat->getHistory();
        $this->assertCount(2, $history);

        $chat->clearHistory();
        $this->assertCount(0, $chat->getHistory());
    }
}
