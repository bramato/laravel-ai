<?php

namespace Bramato\LaravelAi\Facades;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Models\LlmModel;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade for easy access to the Laravel AI functionality.
 *
 * Provides static access to the methods defined in LaravelAiManager.
 *
 * @method static ChatResponse chat(ChatRequest $request) Sends a request to the default provider.
 * @method static LlmClientInterface provider(string $providerName) Gets a client instance for a specific provider.
 * @method static LlmClientInterface defaultClient() Gets the client instance for the default provider.
 * @method static string ask(string $prompt, ?LlmModel $model = null, array $options = []) Ask a simple question and get string content back.
 * @method static string askWithSystem(string $prompt, string $systemMessage, ?LlmModel $model = null, array $options = []) Ask a simple question with a system message and get string content back.
 *
 * @see \Bramato\LaravelAi\LaravelAiManager
 * @see \Bramato\LaravelAi\Contracts\LlmClientInterface
 */
class LaravelAi extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        // Returns the alias bound to LaravelAiManager in the Service Provider
        return 'laravel-ai';
    }
}
