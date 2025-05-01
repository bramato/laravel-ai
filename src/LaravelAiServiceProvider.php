<?php

namespace Bramato\LaravelAi;

// Remove unused client imports as resolution is handled by the Manager
// use Bramato\LaravelAi\Clients\ClaudeClient;
// use Bramato\LaravelAi\Clients\DeepSeekClient;
// use Bramato\LaravelAi\Clients\GeminiClient;
// use Bramato\LaravelAi\Clients\OpenAiClient;
use Bramato\LaravelAi\Contracts\ImageDescriptionServiceInterface;
use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\Services\ChatService;
use Bramato\LaravelAi\Services\ClassificationService;
use Bramato\LaravelAi\Services\ImageDescriptionService;
use Bramato\LaravelAi\Services\MultiTranslationService;
use Bramato\LaravelAi\Services\SummarizationService;
use Bramato\LaravelAi\Services\TranslationService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException; // Keep for potential future config checks

/**
 * Service Provider for the Laravel AI Client package.
 *
 * Registers the LaravelAiManager, LlmClientInterface binding, handles configuration merging and publishing,
 * and implements DeferrableProvider for performance.
 */
class LaravelAiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap any application services.
     *
     * Handles publishing the configuration file when running in the console.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-ai.php' => config_path('laravel-ai.php'),
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
     * @throws \InvalidArgumentException If configuration is missing or invalid.
     */
    public function register(): void
    {
        // Merge the default package config with the application's published version.
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-ai.php', 'laravel-ai');

        // Bind the Manager as a singleton.
        $this->app->singleton(LaravelAiManager::class, function ($app) {
            // Pass config directly to Manager constructor for simplicity
            return new LaravelAiManager(
                $app,
                $app->make(HttpClientFactory::class),
                $app['config']['laravel-ai'] ?? []
            );
        });
        $this->app->alias(LaravelAiManager::class, 'laravel-ai');

        // Bind the main interface to resolve the *default* client via the Manager.
        $this->app->bind(LlmClientInterface::class, function ($app) {
            return $app->make(LaravelAiManager::class)->defaultClient();
        });

        // Bind the ChatService (not as a singleton)
        $this->app->bind(ChatService::class, function ($app) {
            return new ChatService($app->make(LlmClientInterface::class));
        });

        // Bind the ClassificationService (not as singleton)
        $this->app->bind(ClassificationService::class, function ($app) {
            return new ClassificationService($app->make(LaravelAiManager::class));
        });

        // Bind the SummarizationService (not as singleton)
        $this->app->bind(SummarizationService::class, function ($app) {
            return new SummarizationService($app->make(LaravelAiManager::class));
        });

        // Bind the TranslationService (not as singleton)
        $this->app->bind(TranslationService::class, function ($app) {
            return new TranslationService($app->make(LaravelAiManager::class));
        });

        // Bind the MultiTranslationService (not as singleton)
        $this->app->bind(MultiTranslationService::class, function ($app) {
            return new MultiTranslationService($app->make(LaravelAiManager::class));
        });

        // Bind the ImageDescriptionService interface to implementation
        $this->app->bind(ImageDescriptionServiceInterface::class, function ($app) {
            // Requires OpenAiClient specifically, as per current implementation
            // Need to resolve OpenAiClient correctly
            $openAiClient = $app->make('laravel-ai.client.openai'); // Use the specific client binding

            return new ImageDescriptionService(
                $openAiClient,
                $app->make(HttpClientFactory::class)
            );
        });

        // Bind individual client builders, resolved via the Manager
        $this->bindClient('openai', Clients\OpenAiClient::class);
        $this->bindClient('gemini', Clients\GeminiClient::class);
        $this->bindClient('claude', Clients\ClaudeClient::class);
        $this->bindClient('anthropic', Clients\ClaudeClient::class); // Alias claude for anthropic provider key
        $this->bindClient('deepseek', Clients\DeepSeekClient::class);
    }

    /**
     * Helper method to bind a specific client implementation.
     *
     * @param  string  $name  The provider name (key in config)
     * @param  string  $class  The FQCN of the client class
     */
    protected function bindClient(string $name, string $class): void
    {
        $this->app->bind("laravel-ai.client.{$name}", function ($app) use ($name, $class) {
            $manager = $app->make(LaravelAiManager::class);
            $config = $manager->getProviderConfig($name); // Use manager to get config

            $apiKey = $config['api_key'] ?? null;
            $model = $config['model'] ?? null;
            $options = $config['options'] ?? [];

            if (empty($apiKey)) {
                throw new InvalidArgumentException("API key is not configured for the provider '{$name}'.");
            }

            // Ensure the class exists before trying to instantiate
            if (! class_exists($class)) {
                throw new InvalidArgumentException("Client class '{$class}' not found for provider '{$name}'.");
            }

            return new $class(
                $app->make(HttpClientFactory::class),
                $apiKey,
                $model,
                $options
            );
        });
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
        // Add the client bindings to the provides array for deferred loading
        $clientBindings = [];
        $config = $this->app['config']['laravel-ai'] ?? ['providers' => []];
        foreach (array_keys($config['providers']) as $providerName) {
            $clientBindings[] = "laravel-ai.client.{$providerName}";
        }
        // Ensure anthropic alias is included if claude is configured
        if (isset($config['providers']['claude']) && ! in_array('laravel-ai.client.anthropic', $clientBindings)) {
            $clientBindings[] = 'laravel-ai.client.anthropic';
        }

        return array_merge([
            LaravelAiManager::class,
            LlmClientInterface::class,
            'laravel-ai',
            ChatService::class,
            ClassificationService::class,
            SummarizationService::class,
            TranslationService::class,
            MultiTranslationService::class,
            ImageDescriptionServiceInterface::class,
        ], $clientBindings);
    }
}
