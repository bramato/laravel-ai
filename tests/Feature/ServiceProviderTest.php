<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\LaravelAiServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('loads the service provider', function () {
    // Check if the provider is loaded by Testbench
    $loadedProviders = $this->app->getLoadedProviders();
    expect($loadedProviders[LaravelAiServiceProvider::class])->toBeTrue();
});

it('registers the main interface binding', function () {
    // Check if the interface is bound in the container
    expect($this->app->bound(LlmClientInterface::class))->toBeTrue();

    // Check if the facade alias resolves to the interface
    expect($this->app->bound('laravel-ai'))->toBeTrue();
    expect($this->app->make('laravel-ai'))->toBeInstanceOf(LlmClientInterface::class);
    expect($this->app->make(LlmClientInterface::class))->toBe($this->app->make('laravel-ai'));
});

it('merges the configuration correctly', function () {
    // Access the config values set in TestCase::getEnvironmentSetUp
    $defaultProvider = config('laravel-ai.default');
    $openaiKey = config('laravel-ai.providers.openai.api_key');

    expect($defaultProvider)->toBe('openai');
    expect($openaiKey)->toBe('test-openai-key');

    // Check a value expected from the actual config file (merged)
    $claudeVersion = config('laravel-ai.providers.claude.options.version');
    expect($claudeVersion)->toBe('2023-06-01'); // Default from config file
});

it('publishes the configuration file', function () {
    // Target path for the published config
    $publishedConfigPath = config_path('laravel-ai.php');

    // Ensure the file doesn't exist before publishing
    if (File::exists($publishedConfigPath)) {
        File::delete($publishedConfigPath);
    }

    expect(File::exists($publishedConfigPath))->toBeFalse();

    // Run the vendor:publish command
    Artisan::call('vendor:publish', [
        '--provider' => LaravelAiServiceProvider::class,
        '--tag' => 'config',
    ]);

    // Check if the file was published
    expect(File::exists($publishedConfigPath))->toBeTrue();

    // Optional: Compare content with the source config file
    $sourceConfigPath = __DIR__ . '/../../config/laravel-ai.php';
    expect(File::get($publishedConfigPath))->toBe(File::get($sourceConfigPath));

    // Clean up the published file
    File::delete($publishedConfigPath);
});
