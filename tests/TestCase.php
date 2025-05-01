<?php

namespace Bramato\LaravelAi\Tests;

use Bramato\LaravelAi\LaravelAiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Optional: Add factory discovery if you plan to use factories
        // Factory::guessFactoryNamesUsing(
        //     fn (string $modelName) => 'Bramato\\LaravelAi\\Database\\Factories\\'.class_basename($modelName).'Factory'
        // );
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>\Illuminate\Support\ServiceProvider
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    public function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        /*
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        */

        // Setup package config
        // Load the actual package config file
        $packageConfigFile = __DIR__.'/../config/laravel-ai.php';
        if (file_exists($packageConfigFile)) {
            // Merge the package config into the application's config
            $app['config']->set('laravel-ai', require $packageConfigFile);
        }
        // Override specific values for testing if needed
        $app['config']->set('laravel-ai.default', 'openai'); // Ensure a default is set
        $app['config']->set('laravel-ai.providers.openai.api_key', 'test-openai-key');
        $app['config']->set('laravel-ai.providers.openai.model', 'gpt-test');
        $app['config']->set('laravel-ai.providers.gemini.api_key', 'test-gemini-key');
        $app['config']->set('laravel-ai.providers.gemini.model', 'gemini-test');
        $app['config']->set('laravel-ai.providers.claude.api_key', 'test-claude-key');
        $app['config']->set('laravel-ai.providers.claude.model', 'claude-test');
        $app['config']->set('laravel-ai.providers.deepseek.api_key', 'test-deepseek-key');
        $app['config']->set('laravel-ai.providers.deepseek.model', 'deepseek-test');
    }
}
