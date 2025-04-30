<?php

namespace Bramato\LaravelAi\Facades;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade for easy access to the default LLM client.
 *
 * Provides static access to the methods defined in LlmClientInterface.
 *
 * @method static ChatResponse chat(ChatRequest $request)
 * @method static LlmClientInterface provider(string $providerName)
 *
 * @see \Bramato\LaravelAi\Contracts\LlmClientInterface
 * @see \Bramato\LaravelAi\LaravelAiManager // Assuming a Manager class might handle provider switching later
 */
class LaravelAi extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * Resolves the LlmClientInterface binding from the service container.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        // Returns the binding key used in the Service Provider
        return LlmClientInterface::class; // Recommended way
    }
}
