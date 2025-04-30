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

/**
 * Service Provider for the Laravel AI Client package.
 *
 * Registers the LlmClientInterface binding, handles configuration merging and publishing,
 * and implements DeferrableProvider for performance.
 */
class LaravelAiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap any application services.
     *
     * Handles publishing the configuration file when running in the console.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laravel-ai.php' => config_path('laravel-ai.php'),
            ], 'laravel-ai-config'); // Use a more specific tag

            // --- Commented out placeholder publish groups ---
            // Add these back if views, assets, translations, or commands are implemented.
            /*
            // Publishing the views.
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-ai'),
            ], 'laravel-ai-views');

            // Publishing assets.
            $this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-ai'),
            ], 'laravel-ai-assets');

            // Publishing the translation files.
            $this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-ai'),
            ], 'laravel-ai-lang');

            // Registering package commands.
            $this->commands([]);
            */
        }
    }

    /**
     * Register any application services.
     *
     * Merges the package configuration and sets up the singleton binding
     * for the LlmClientInterface using a factory pattern based on the
     * configured default provider.
     *
     * @return void
     * @throws \InvalidArgumentException If configuration is missing or invalid.
     */
    public function register(): void
    {
        // Merge the default package config with the application's published version.
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-ai.php', 'laravel-ai');

        // Bind the main interface to the factory logic using a singleton.
        // This ensures only one instance of the configured client is created per request cycle.
        $this->app->singleton(LlmClientInterface::class, function ($app) {
            $config = $app['config']['laravel-ai'];
            $defaultProvider = $config['default'] ?? null;

            // Validate that a default provider is set.
            if (!$defaultProvider) {
                throw new InvalidArgumentException('Default LLM provider is not defined in the laravel-ai configuration file.');
            }

            // Validate that the configuration exists for the default provider.
            if (!isset($config['providers'][$defaultProvider])) {
                throw new InvalidArgumentException("Configuration for default LLM provider '{$defaultProvider}' not found in laravel-ai.providers.");
            }

            // Extract configuration details for the chosen provider.
            $providerConfig = $config['providers'][$defaultProvider];
            $apiKey = $providerConfig['api_key'] ?? null;
            $model = $providerConfig['model'] ?? null;
            $options = $providerConfig['options'] ?? []; // Optional settings

            // Validate essential configuration values.
            if (empty($apiKey) || empty($model)) {
                throw new InvalidArgumentException("API key or model is not configured for the default provider '{$defaultProvider}'. Check your .env file or laravel-ai.php config.");
            }

            // Resolve the HTTP client factory from the container.
            $httpClientFactory = $app->make(HttpClientFactory::class);

            // Instantiate the correct client based on the default provider key.
            return match ($defaultProvider) {
                'openai' => new OpenAiClient($httpClientFactory, $apiKey, $model, $options),
                'gemini' => new GeminiClient($httpClientFactory, $apiKey, $model, $options),
                'claude' => new ClaudeClient($httpClientFactory, $apiKey, $model, $options),
                'deepseek' => new DeepSeekClient($httpClientFactory, $apiKey, $model, $options),
                default => throw new InvalidArgumentException("Unsupported LLM provider specified: {$defaultProvider}"),
            };
        });

        // Alias the interface binding for potential use with the Facade or direct resolution.
        $this->app->alias(LlmClientInterface::class, 'laravel-ai');
    }

    /**
     * Get the services provided by the provider.
     *
     * Enables deferred loading of the service provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            LlmClientInterface::class,
            'laravel-ai', // The alias registered above.
        ];
    }
}
