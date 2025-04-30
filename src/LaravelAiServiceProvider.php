<?php

namespace Bramato\LaravelAi;

use Bramato\LaravelAi\Clients\ClaudeClient;
use Bramato\LaravelAi\Clients\DeepSeekClient;
use Bramato\LaravelAi\Clients\GeminiClient;
use Bramato\LaravelAi\Clients\OpenAiClient;
use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LaravelAiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        // Bootstrap package services (routes, views, config, etc.)

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laravel-ai.php' => config_path('laravel-ai.php'),
            ], 'config');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-ai'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-ai'),
            ], 'assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-ai'),
            ], 'lang');*/

            // Registering package commands.
            // $this->commands([]);
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-ai.php', 'laravel-ai');

        // Bind the main interface to the factory logic
        $this->app->singleton(LlmClientInterface::class, function ($app) {
            $config = $app['config']['laravel-ai'];
            $defaultProvider = $config['default'] ?? null;

            if (!$defaultProvider) {
                throw new InvalidArgumentException('Default LLM provider is not defined in laravel-ai config.');
            }

            if (!isset($config['providers'][$defaultProvider])) {
                throw new InvalidArgumentException("Configuration for default LLM provider '{$defaultProvider}' not found.");
            }

            $providerConfig = $config['providers'][$defaultProvider];
            $apiKey = $providerConfig['api_key'] ?? null;
            $model = $providerConfig['model'] ?? null;
            $options = $providerConfig['options'] ?? [];

            if (empty($apiKey) || empty($model)) {
                throw new InvalidArgumentException("API key or model is not configured for the default provider '{$defaultProvider}'.");
            }

            $httpClientFactory = $app->make(HttpClientFactory::class);

            return match ($defaultProvider) {
                'openai' => new OpenAiClient($httpClientFactory, $apiKey, $model, $options),
                'gemini' => new GeminiClient($httpClientFactory, $apiKey, $model, $options),
                'claude' => new ClaudeClient($httpClientFactory, $apiKey, $model, $options),
                'deepseek' => new DeepSeekClient($httpClientFactory, $apiKey, $model, $options),
                default => throw new InvalidArgumentException("Unsupported LLM provider specified: {$defaultProvider}"),
            };
        });

        // Optional: Alias for the facade (can use the interface binding directly)
        $this->app->alias(LlmClientInterface::class, 'laravel-ai');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            LlmClientInterface::class,
            'laravel-ai', // The alias
        ];
    }
}
