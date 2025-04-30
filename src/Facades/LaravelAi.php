<?php

namespace Bramato\LaravelAi\Facades;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ChatResponse chat(ChatRequest $request)
 *
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
        // Returns the binding key used in the Service Provider
        return LlmClientInterface::class; // Or return 'laravel-ai';
    }
}
