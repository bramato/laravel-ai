# Laravel AI Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bramato/laravel-ai.svg?style=flat-square)](https://packagist.org/packages/bramato/laravel-ai)
[![Total Downloads](https://img.shields.io/packagist/dt/bramato/laravel-ai.svg?style=flat-square)](https://packagist.org/packages/bramato/laravel-ai)

This package provides a unified Laravel client to interact with the chat APIs of various Large Language Models (LLMs) such as ChatGPT (OpenAI), Gemini (Google), Claude (Anthropic), and DeepSeek.

The goal is to abstract the differences between the APIs, offering a single, consistent interface within the Laravel framework.

## Core Structure

The core of the package is the `Bramato\LaravelAi\Contracts\LlmClientInterface` interface, which defines the contract for interacting with LLM providers. Data Transfer Objects (DTOs) are implemented using the [`wendelladriel/laravel-validated-dto`](https://github.com/WendellAdriel/laravel-validated-dto) package to ensure data consistency and validation.

```php
namespace Bramato\LaravelAi\Contracts;

use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;

interface LlmClientInterface
{
    public function chat(ChatRequest $request): ChatResponse;
}
```

The `ChatRequest` and `ChatResponse` DTOs handle the data structure for requests and responses:

```php
// src/DTOs/ChatRequest.php highlights
class ChatRequest extends ValidatedDTO
{
    public string $prompt;
    public ?string $systemMessage;
    public array $history;
    public array $options;
    public bool $jsonMode;
    // ... validation, defaults, casts ...
}

// src/DTOs/ChatResponse.php highlights
class ChatResponse extends SimpleDTO
{
    public string $content;
    public string $finishReason;
    public string $model;
    public string $id;
    public ?array $usage;
    public bool $isJson;
    public mixed $decodedJsonContent;
    public ?array $rawResponse;
    // ... defaults, casts ...
}
```

## Installation (TODO)

You can install the package via composer:

```bash
composer require bramato/laravel-ai
```

## Configuration (TODO)

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Bramato\LaravelAi\LaravelAiServiceProvider" --tag="config"
```

Set the API keys and other options in your `.env` file and/or `config/laravel-ai.php`.

## Usage (TODO)

```php
use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;

// Via Dependency Injection
$client = app(LlmClientInterface::class);

$request = new ChatRequest(
    prompt: 'What is the meaning of life?',
    // Optional parameters:
    // systemMessage: 'You are a helpful assistant.',
    // history: [ ['role' => 'user', 'content' => 'Previous question'] ],
    // options: ['temperature' => 0.7],
    // jsonMode: false
);

$response = $client->chat($request);

echo $response->content;

// Via Facade (if configured)
use Bramato\LaravelAi\Facades\LaravelAi;

$responseViaFacade = LaravelAi::chat($request);
echo $responseViaFacade->content;
```

## Testing (TODO)

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [Bramato](https://github.com/bramato)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Client Implementations

The package uses the Strategy pattern, with concrete client implementations for each supported provider residing in the `src/Clients/` directory (e.g., `OpenAiClient.php`).
