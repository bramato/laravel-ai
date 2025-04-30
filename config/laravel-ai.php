<?php

// config for Bramato/LaravelAi
return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default LLM provider that will be used by the
    | package when no specific provider is specified. You may set this to
    | any of the available providers listed below.
    |
    */

    'default' => env('LLM_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | LLM Providers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the settings for each LLM provider supported by
    | your application. You are free to add more providers or adjust the
    | settings for existing ones. Make sure to configure the API key and
    | desired model for each provider you intend to use.
    |
    */

    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'), // Or your preferred default
            'options' => [
                // Example: Override base URI if using a proxy or Azure
                // 'base_uri' => env('OPENAI_BASE_URI'),
                // 'organization' => env('OPENAI_ORGANIZATION'),
                // 'timeout' => env('OPENAI_TIMEOUT', 30),
            ],
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-flash-latest'),
            'options' => [
                // Example: Specify API version if needed
                // 'version' => 'v1beta',
                // 'timeout' => env('GEMINI_TIMEOUT', 30),
            ],
        ],

        'claude' => [
            'api_key' => env('CLAUDE_API_KEY'),
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20240620'), // Or your preferred default
            'options' => [
                // Example: Set Anthropic API version (required)
                'version' => env('CLAUDE_API_VERSION', '2023-06-01'),
                // 'timeout' => env('CLAUDE_TIMEOUT', 30),
            ],
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'options' => [
                // 'timeout' => env('DEEPSEEK_TIMEOUT', 30),
            ],
        ],

    ],

];
