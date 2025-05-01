<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface; // Needed for mocking
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\LaravelAiServiceProvider;
use Bramato\LaravelAi\Models\LlmModel;
use Mockery;
use Orchestra\Testbench\TestCase;

class SimpleChatHelperTest extends TestCase
{
    protected function getPackageProviders($app)
    {
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

    /**
     * Helper function to create a complete ChatResponse array for mocking.
     */
    private function createMockResponseData(string $content, string $model = 'mock-model'): array
    {
        return [
            'id' => 'chatcmpl-'.uniqid(),
            'model' => $model,
            'content' => $content,
            'finishReason' => 'stop',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15],
            'isJson' => false,
            'decodedJsonContent' => null,
            'rawResponse' => ['mocked' => true],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Override application setup.
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('laravel-ai.default', 'openai');
        $app['config']->set('laravel-ai.providers.openai', [
            'api_key' => 'test-key-openai',
            'model' => 'gpt-test-default',
        ]);
        $app['config']->set('laravel-ai.providers.claude', [ // Keep claude config for anthropic alias
            'api_key' => 'test-key-anthropic',
            'model' => 'claude-test-default',
            'options' => ['version' => '2023-06-01'],
        ]);
        // No need for aliases here anymore, we mock the specific binding keys
    }

    /** @test */
    public function ask_uses_default_provider_and_returns_content()
    {
        // Mock the interface and bind it to the specific client key
        $mockClient = Mockery::mock(LlmClientInterface::class);
        $this->app->instance('laravel-ai.client.openai', $mockClient);

        $expectedResponse = 'This is the default response.';

        $mockClient->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) {
                return $request->prompt === 'Hello default'
                    && $request->systemMessage === null
                    && empty($request->history)
                    && ! isset($request->options['model']); // Default uses config model
            })
            ->andReturn(new ChatResponse($this->createMockResponseData($expectedResponse)));

        // Call the Facade method
        $responseContent = LaravelAi::ask('Hello default');

        $this->assertEquals($expectedResponse, $responseContent);
    }

    /** @test */
    public function ask_with_system_uses_default_provider_and_returns_content()
    {
        // Mock the interface and bind it to the specific client key
        $mockClient = Mockery::mock(LlmClientInterface::class);
        $this->app->instance('laravel-ai.client.openai', $mockClient);

        $expectedResponse = 'System response here.';

        $mockClient->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) {
                return $request->prompt === 'Translate this'
                    && $request->systemMessage === 'Be a translator.'
                    && empty($request->history);
            })
            ->andReturn(new ChatResponse($this->createMockResponseData($expectedResponse)));

        // Call the Facade method
        $responseContent = LaravelAi::askWithSystem('Translate this', 'Be a translator.');

        $this->assertEquals($expectedResponse, $responseContent);
    }

    /** @test */
    public function ask_uses_specific_model_provider()
    {
        // Mock the interface and bind it to the specific client key
        $mockClaudeClient = Mockery::mock(LlmClientInterface::class);
        $this->app->instance('laravel-ai.client.anthropic', $mockClaudeClient);

        $claudeModel = LlmModel::claudeFlagship();
        $this->assertNotNull($claudeModel);
        $this->assertEquals('anthropic', $claudeModel->provider);

        $expectedResponse = 'Claude flagship answers.';

        $mockClaudeClient->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) use ($claudeModel) {
                return $request->prompt === 'Hello Claude flagship'
                    && $request->systemMessage === null
                    && isset($request->options['model'])
                    && $request->options['model'] === $claudeModel->model_id;
            })
            ->andReturn(new ChatResponse($this->createMockResponseData($expectedResponse, $claudeModel->model_id)));

        // Call the Facade method directly
        $responseContent = LaravelAi::ask('Hello Claude flagship', $claudeModel);

        $this->assertEquals($expectedResponse, $responseContent);
    }

    /** @test */
    public function ask_with_system_uses_specific_model_provider()
    {
        // Mock the interface and bind it to the specific client key
        $mockClaudeClient = Mockery::mock(LlmClientInterface::class);
        $this->app->instance('laravel-ai.client.anthropic', $mockClaudeClient);

        $claudeModel = LlmModel::claudeFlagship();
        $this->assertNotNull($claudeModel);
        $this->assertEquals('anthropic', $claudeModel->provider);

        $expectedResponse = 'Claude system response.';

        $mockClaudeClient->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) use ($claudeModel) {
                return $request->prompt === 'Specific question'
                    && $request->systemMessage === 'Use Claude logic'
                    && isset($request->options['model'])
                    && $request->options['model'] === $claudeModel->model_id;
            })
            ->andReturn(new ChatResponse($this->createMockResponseData($expectedResponse, $claudeModel->model_id)));

        // Call the Facade method directly
        $responseContent = LaravelAi::askWithSystem('Specific question', 'Use Claude logic', $claudeModel);

        $this->assertEquals($expectedResponse, $responseContent);
    }

    /** @test */
    public function ask_passes_options_correctly()
    {
        // Mock the interface and bind it to the specific client key
        $mockClient = Mockery::mock(LlmClientInterface::class);
        $this->app->instance('laravel-ai.client.openai', $mockClient);

        $expectedResponse = 'Response with high temperature.';
        $options = ['temperature' => 0.9];

        $mockClient->shouldReceive('chat')
            ->once()
            ->withArgs(function (ChatRequest $request) use ($options) {
                return $request->prompt === 'Be creative'
                    && $request->options === $options;
            })
            ->andReturn(new ChatResponse($this->createMockResponseData($expectedResponse)));

        // Call the Facade method
        $responseContent = LaravelAi::ask('Be creative', null, $options);

        $this->assertEquals($expectedResponse, $responseContent);
    }
}
