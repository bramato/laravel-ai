# Laravel AI Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bramato/laravel-ai.svg?style=flat-square)](https://packagist.org/packages/bramato/laravel-ai)
[![Total Downloads](https://img.shields.io/packagist/dt/bramato/laravel-ai.svg?style=flat-square)](https://packagist.org/packages/bramato/laravel-ai)

This package provides a unified Laravel client to interact with the chat APIs of various Large Language Models (LLMs):

-   **OpenAI** (ChatGPT models like `gpt-4`, `gpt-3.5-turbo`)
-   **Google Gemini** (e.g., `gemini-1.5-pro-latest`, `gemini-1.0-pro`)
-   **Anthropic Claude** (e.g., `claude-3-opus-20240229`, `claude-3-sonnet-20240229`)
-   **DeepSeek** (Coder and Chat models)

The goal is to abstract the differences between these APIs, offering a single, consistent interface (`LlmClientInterface`) and Facade (`LaravelAi`) within your Laravel application.

## Core Features

-   Unified `chat()` method for all providers.
-   Validated Data Transfer Objects (`ChatRequest`, `ChatResponse`) using [`wendelladriel/laravel-validated-dto`](https://github.com/WendellAdriel/laravel-validated-dto).
-   Configurable default provider and provider-specific settings (API keys, models, options).
-   Facade for easy access (`LaravelAi::chat(...)`).
-   Support for chat history and system messages.
-   JSON Mode for structured output (where supported by the provider).
-   Custom exceptions for API errors.

## Installation

You can install the package via Composer:

```bash
composer require bramato/laravel-ai
```

The package utilizes Laravel's auto-discovery, so the Service Provider and Facade should be registered automatically.

## Configuration

1.  **Publish the Configuration File:**

    ```bash
    php artisan vendor:publish --provider="Bramato\LaravelAi\LaravelAiServiceProvider" --tag="laravel-ai-config"
    ```

    This will create a `config/laravel-ai.php` file.

2.  **Configure Environment Variables:**

    Add the necessary API keys and desired default models to your `.env` file. The configuration file reads these values.

    ```dotenv
    # config/laravel-ai.php
    LARAVEL_AI_DEFAULT_PROVIDER=openai

    # OpenAI Configuration
    OPENAI_API_KEY=your_openai_api_key
    OPENAI_MODEL=gpt-4-turbo
    OPENAI_ORGANIZATION=your_openai_org_id # Optional: Add your organization ID

    # Gemini Configuration
    GEMINI_API_KEY=your_gemini_api_key
    GEMINI_MODEL=gemini-1.5-pro-latest
    GEMINI_API_VERSION=v1beta # Recommended for full features like JSON mode
    # Example for safety settings (optional, configure in config/laravel-ai.php for complex values)
    # GEMINI_SAFETY_SETTINGS_HARM_CATEGORY_HATE_SPEECH=BLOCK_ONLY_HIGH

    # Claude Configuration
    CLAUDE_API_KEY=your_claude_api_key
    CLAUDE_MODEL=claude-3-sonnet-20240229
    CLAUDE_API_VERSION=2023-06-01 # Required by Claude
    # CLAUDE_BASE_URI=https://api.anthropic.com/v1 (Default)

    # DeepSeek Configuration
    DEEPSEEK_API_KEY=your_deepseek_api_key
    DEEPSEEK_MODEL=deepseek-chat
    DEEPSEEK_BASE_URI=https://api.deepseek.com/v1 # Default, if using OpenAI compatible endpoint
    ```

3.  **Review `config/laravel-ai.php` (Optional):**

    You can directly modify the `config/laravel-ai.php` file to:

    -   Set the `default` provider.
    -   Override environment variables.
    -   Configure provider-specific `options` like `base_uri`, `timeout`, `version` (for Gemini/Claude), `organization` (for OpenAI), `safety_settings` (for Gemini - complex array structure, recommended here), or other API-specific parameters.

## Usage

You can interact with the LLM providers using either Dependency Injection or the Facade.

### Using the Facade

The simplest way is to use the `LaravelAi` facade.

```php
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\DTOs\ChatRequest;

// Simple prompt
$request = new ChatRequest(['prompt' => 'Tell me a short story about a brave robot.']);
$response = LaravelAi::chat($request);

echo $response->content; // Get the main text content

// With history and system message
$requestWithHistory = new ChatRequest([
    'prompt' => 'What was the robot\'s name?',
    'systemMessage' => 'You are a storyteller.',
    'history' => [
        ['role' => 'user', 'content' => 'Tell me a short story about a brave robot.'],
        ['role' => 'assistant', 'content' => 'Once upon a time, there was a robot named Bolt...'],
    ],
]);
$responseWithHistory = LaravelAi::chat($requestWithHistory);
echo $responseWithHistory->content;

// Requesting JSON output
$requestJson = new ChatRequest([
    'prompt' => 'Provide user details in JSON format: {name: string, email: string}.',
    'jsonMode' => true,
]);
$responseJson = LaravelAi::chat($requestJson);

if ($responseJson->isJson) {
    $userData = $responseJson->decodedJsonContent;
    // $userData is now an associative array: ['name' => '...', 'email' => '...']
    print_r($userData);
} else {
    // Handle cases where the LLM failed to return valid JSON
    echo "Failed to get JSON response: " . $responseJson->content;
}

// Using a specific provider (overrides default)
$geminiResponse = LaravelAi::provider('gemini')->chat(new ChatRequest(['prompt' => 'Hello from Gemini!']));
echo $geminiResponse->content;

$claudeResponse = LaravelAi::provider('claude')->chat(new ChatRequest(['prompt' => 'Hello from Claude!']));
echo $claudeResponse->content;
```

### Using Dependency Injection

You can also inject the `LlmClientInterface`.

```php
use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;

class MyService
{
    public function __construct(private LlmClientInterface $llmClient)
    {}

    public function askSomething(string $prompt): string
    {
        $request = new ChatRequest(['prompt' => $prompt]);
        $response = $this->llmClient->chat($request);
        return $response->content;
    }
}
```

### The `ChatRequest` DTO

This DTO holds all the input parameters for the `chat` method. It uses `wendelladriel/laravel-validated-dto` for validation.

-   `prompt` (string, required): The main user message/question.
-   `systemMessage` (string, optional): Instructions for the AI's persona or behavior.
-   `history` (array, optional): An array of previous messages for context. Each message should be an associative array with `role` (`user` or `assistant`) and `content` (string).
    -   **Important:** History must alternate roles (user, assistant, user, ...). Claude and Gemini enforce this strictly.
-   `options` (array, optional): Provider-specific options like `temperature`, `max_tokens`, `top_p`, `frequency_penalty`, `presence_penalty`, `stop`, `seed`, `stream`, `logprobs` (OpenAI), `top_logprobs` (OpenAI), `topK`, `topP` (Gemini), `candidateCount` (Gemini), `stop_sequences` (Claude), `user` (OpenAI). Refer to the specific LLM provider's documentation for available options. The package attempts to map common options where names differ (e.g., `max_tokens` -> Gemini `maxOutputTokens`).
-   `jsonMode` (bool, optional, default: `false`): If `true`, instructs the LLM to return a JSON response. See **Provider Notes** below for implementation details.

### The `ChatResponse` DTO

This DTO holds the results from the `chat` method.

-   `content` (string): The main text content of the response.
-   `finishReason` (string): The reason the LLM stopped generating text (e.g., `stop`, `length`, `tool_calls`, `MAX_TOKENS`). Value varies by provider.
-   `model` (string): The specific model ID that generated the response.
-   `id` (string): A unique identifier for the chat interaction provided by the API (format varies by provider, generated for Gemini).
-   `usage` (array, optional): Token usage information (e.g., `prompt_tokens`, `completion_tokens`, `total_tokens`). Structure may vary by provider (check `rawResponse` for details).
-   `isJson` (bool): Indicates if `jsonMode` was requested _and_ the `content` was successfully decoded as JSON (or extracted and decoded for Claude).
-   `decodedJsonContent` (mixed): If `isJson` is true, this holds the PHP associative array/value decoded from the JSON `content`. Otherwise, it's `null`.
-   `rawResponse` (array, optional): The original, unprocessed response array from the provider's API for debugging or accessing non-standard data.

### Handling Errors

The package throws custom exceptions extending `\Exception` located in `Bramato\LaravelAi\Exceptions`:

-   `LlmApiException`: General API errors (server errors 5xx, rate limits 429, bad requests 400, etc.). The message often includes the provider's error code/type and message.
-   `AuthenticationException`: Errors related to invalid API keys or permissions (typically 401, 403).
-   `InvalidResponseException`: Errors when the API response is malformed, blocked by safety settings (Gemini), or otherwise unusable despite a successful HTTP status.

You should wrap your `LaravelAi::chat()` calls in `try...catch` blocks to handle potential issues.

```php
try {
    $response = LaravelAi::chat(new ChatRequest(['prompt' => $userPrompt]));
    // Process response
} catch (\Bramato\LaravelAi\Exceptions\AuthenticationException $e) {
    Log::error("AI Authentication Failed: " . $e->getMessage());
    // Handle auth error (e.g., notify admin)
} catch (\Bramato\LaravelAi\Exceptions\LlmApiException $e) {
    Log::error("AI API Error: " . $e->getMessage());
    // Handle general API error (e.g., show user a message)
} catch (\Throwable $e) {
    Log::error("Unexpected AI Error: " . $e->getMessage());
    // Handle unexpected errors
}
```

## Provider Notes

-   **JSON Mode:**
    -   **OpenAI / DeepSeek:** Supported via the `response_format` parameter (`{ "type": "json_object" }`). Works best with models explicitly trained for JSON output. You must still guide the model via the prompt to produce the desired JSON structure.
    -   **Gemini:** Supported via the `generationConfig.response_mime_type` parameter (`application/json`). Requires the `v1beta` API version (configurable in `laravel-ai.php` options or `GEMINI_API_VERSION` env var).
    -   **Claude:** Does **not** have a dedicated API parameter. Setting `jsonMode: true` will make the package _attempt_ to extract JSON from the response (looking for `json\n{...}\n` blocks) and parse it. You **must** explicitly instruct Claude to return JSON within your `prompt` or `systemMessage` for it to work reliably.
-   **Provider Options:** Use the `options` key in `config/laravel-ai.php` or the `options` array in `ChatRequest` to pass provider-specific parameters (like `organization` for OpenAI or `safety_settings` for Gemini). Options passed in `ChatRequest` usually take precedence if supported, but check individual client implementations if needed.
-   **Claude Headers:** Requires `x-api-key` and `anthropic-version` headers. These are handled automatically based on the configuration (`api_key` and `options.version`).
-   **Claude History:** Strictly requires alternating `user` and `assistant` roles in the `history` array. The last message in the history _before_ the current prompt must be from the `assistant`.
-   **Gemini History:** Requires alternating `user` and `model` (maps from `assistant`) roles. The conversation must start with a `user` role.
-   **System Prompts:** Implementation varies slightly. The package maps the `systemMessage` DTO property to the appropriate mechanism (`system` parameter for Claude, first `user` message followed by `model` placeholder for Gemini, first `system` message for OpenAI/DeepSeek).

## Available Models (LlmModel)

Starting from version 1.1, the package includes an Eloquent model `Bramato\LaravelAi\Models\LlmModel` that provides an easy way to list and query the capabilities of the LLM models known to the package. This uses the [`calebporzio/sushi`](https://github.com/calebporzio/sushi) package to provide a database-like interface to an internal array of model data.

The `LlmModel` has the following attributes:

-   `provider` (string): The provider key (e.g., 'openai', 'google', 'anthropic', 'deepseek').
-   `model_id` (string): The unique identifier for the model used in API calls.
-   `description` (string): A brief description of the model.
-   `context_window` (int): The maximum number of tokens the model can handle in its context window.
-   `json_mode` (bool): Indicates if the model supports a dedicated JSON output mode (or if the package can reliably extract JSON via specific instructions, like with Claude).
-   `supports_vision` (bool): Indicates if the model can process image input.
-   `max_output_tokens` (int): The maximum number of tokens the model can generate in a single response.
-   `flagship` (bool): Indicates if this model is considered the primary/most capable model for the provider within this package's list (useful for selecting a default powerful model).

You can query this model like any other Eloquent model:

```php
use Bramato\LaravelAi\Models\LlmModel;

// Get all available models
$allModels = LlmModel::all();

// Get all OpenAI models
$openaiModels = LlmModel::where('provider', 'openai')->get();

// Get models supporting vision
$visionModels = LlmModel::where('supports_vision', true)->get();

// Get models with a context window larger than 100k tokens
$largeContextModels = LlmModel::where('context_window', '>', 100000)->get();

// Find a specific model
$gpt4o = LlmModel::where('model_id', 'gpt-4o')->first();

if ($gpt4o) {
    echo "GPT-4o Context Window: " . $gpt4o->context_window;
    echo "Supports Vision? " . ($gpt4o->supports_vision ? 'Yes' : 'No');
}

// Get the flagship model for a specific provider
$flagshipClaude = LlmModel::claudeFlagship();
if ($flagshipClaude) {
    echo "Claude Flagship: " . $flagshipClaude->model_id;
}

// Get all flagship models
$allFlagships = LlmModel::where('flagship', true)->get();
```

This can be useful for dynamically presenting model options to users or for making decisions within your application based on model capabilities. The model data is based on research at the time of release and may evolve.

## Chat Service (Stateful Conversations)

Introduced in v1.1, the `Bramato\LaravelAi\Services\ChatService` provides a convenient way to manage multi-turn conversations with an LLM while maintaining the conversation state (history, system message, selected model, options).

Instead of manually passing the history back and forth in `ChatRequest`, the `ChatService` handles it internally.

### Getting Started

You initiate a chat session using the static `create` method. This resolves an instance of the service from the container and sets up the initial state.

```php
use Bramato\LaravelAi\Services\ChatService;
use Bramato\LaravelAi\Models\LlmModel;

// Start a simple chat
$chat = ChatService::create(initialPrompt: 'Explain the concept of dependency injection.');

// Get the first response (history is managed internally)
$response1 = $chat->getResponse();
echo "Assistant: " . $response1->content . "\n";

// Add another user message
$chat->addMessage('user', 'Can you give me a simple PHP example?');

// Get the second response (sends the full history: user, assistant, user)
$response2 = $chat->getResponse();
echo "Assistant: " . $response2->content . "\n";

// Access the full history
print_r($chat->getHistory());
/*
Output might look like:
Array
(
    [0] => Array
        (
            [role] => user
            [content] => Explain the concept of dependency injection.
        )
    [1] => Array
        (
            [role] => assistant
            [content] => Dependency injection (DI) is a design pattern...
        )
    [2] => Array
        (
            [role] => user
            [content] => Can you give me a simple PHP example?
        )
    [3] => Array
        (
            [role] => assistant
            [content] => Sure! Consider a Logger class...
        )
)
*/
```

### `ChatService::create()` Parameters

The `create` method accepts several parameters to configure the session:

-   `string $initialPrompt`: (Required) The first message from the user to start the conversation.
-   `?string $systemMessage = null`: An optional system message to guide the AI's behavior throughout the session.
-   `?LlmModel $llmModel = null`: An optional `LlmModel` instance. If provided, the service will use the provider and model specified in this object for the entire session, overriding the default configuration. This is useful for ensuring a specific model or provider is used for a particular chat thread.
-   `array $options = []`: Optional provider-specific options (like `temperature`) to apply to all requests within this session. These merge with and override default configuration options.
-   `mixed $jsonData = null`: Controls JSON mode for the session (overrides `jsonMode` in `ChatRequest`):
    -   `null` or `false`: JSON mode is disabled.
    -   `true`: Enables JSON mode (telling the underlying client to request JSON).
    -   `array`: Enables JSON mode and appends a JSON representation of the array to the `initialPrompt` as a structure/schema example for the LLM.
    -   `string`: Enables JSON mode. If the string is valid JSON, it's appended to the `initialPrompt` as a structure/schema example.

Example with more options:

```php
$claudeHaiku = LlmModel::where('model_id', 'claude-3-haiku-20240307')->first();

$jsonSchema = ['item' => 'string', 'quantity' => 'integer', 'notes' => 'string|null'];

$chat = ChatService::create(
    initialPrompt: 'Parse the following order: 2 apples, 1 banana, special instructions: organic.',
    systemMessage: 'You are an order parsing assistant. Respond ONLY in JSON format.',
    llmModel: $claudeHaiku, // Use Claude Haiku for this chat
    options: ['temperature' => 0.3],
    jsonData: $jsonSchema // Request JSON output matching this structure
);

$response = $chat->getResponse();

if ($response->isJson) {
    print_r($response->decodedJsonContent);
} else {
    echo "Failed to get JSON: " . $response->content;
}
```

### Additional Methods

The `ChatService` instance provides helper methods to inspect or modify the session state after creation:

-   `getHistory(): array`: Returns the current conversation history.
-   `setProvider(string $provider): self`: Overrides the provider for subsequent requests in the session.
-   `setModel(string $model): self`: Overrides the model for subsequent requests.
-   `setOptions(array $options, bool $merge = false): self`: Sets or merges options for subsequent requests.
-   `clearHistory(): self`: Clears the internal conversation history.

These methods allow for dynamic adjustments during a longer conversation if needed.

## Testing

Run the test suite using Pest:

```bash
composer test
```

The tests use Laravel's `Http::fake()` to mock API responses and do not make real API calls.

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

### Simple Chat Helpers

For quick interactions, you can use the `ask` and `askWithSystem` static methods directly on the `LaravelAi` facade. These methods handle the creation of the request and return only the content string from the response.

```php
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\Models\LlmModel;

// Simple question using the default provider/model
$answer = LaravelAi::ask('What is Laravel?');

// Simple question with a system message
$story = LaravelAi::askWithSystem(
    'Write a short story about a robot.',
    'You are a creative storyteller.'
);

// Simple question using a specific model
$geminiFlash = LlmModel::getGeminiFlash(); // Assuming you have this static helper
$specificAnswer = LaravelAi::ask('Translate "hello" to French.', $geminiFlash);

// Passing additional options (e.g., temperature)
$creativeAnswer = LaravelAi::ask(
    'Suggest three names for a new tech startup.',
    options: ['temperature' => 0.9]
);
```

### JSON Extraction Helper

To easily extract structured data (JSON) from a piece of text, use the `extractJson` helper. It instructs the LLM to return JSON based on your instructions and automatically attempts to parse the response.

```php
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\Models\LlmModel;

$text = "User John Doe (age 30) lives in New York. His email is john.doe@example.com.";
$instruction = "Extract the user's name, age, and email address.";

// Extract using the default provider/model
$extractedData = LaravelAi::extractJson($instruction, $text);

// $extractedData might look like: ['name' => 'John Doe', 'age' => 30, 'email' => 'john.doe@example.com']

if ($extractedData) {
    echo "User Email: " . $extractedData['email'];
} else {
    echo "Failed to extract JSON data.";
}

// Extract using a specific model known for good JSON output
$openaiJsonModel = LlmModel::getOpenAiGpt4Turbo(); // Example: Assuming you have this model
$specificExtraction = LaravelAi::extractJson($instruction, $text, $openaiJsonModel);
```

The `extractJson` method returns an associative array on success, or `null` if the extraction fails (e.g., the LLM response wasn't valid JSON or an error occurred).

### Translation Service

For simple text translation, use the `TranslationService`.

```php
use Bramato\LaravelAi\Services\TranslationService;
use Bramato\LaravelAi\Models\LlmModel;

class CommunicationHelper
{
    public function __construct(private TranslationService $translator)
    {}

    public function translateToItalian(string $englishText): ?string
    {
        // Translate from English (auto-detected) to Italian
        return $this->translator->translate(
            text: $englishText,
            targetLanguage: 'Italian'
        );
    }

    public function translateFromGerman(string $germanText, string $targetLang = 'English'): ?string
    {
        // Translate explicitly from German to the target language
        return $this->translator->translate(
            text: $germanText,
            targetLanguage: $targetLang,
            sourceLanguage: 'German' // Specify source
        );
    }
}

// Example usage
$helper = app(CommunicationHelper::class);

$italianGreeting = $helper->translateToItalian('Hello, how are you?');
echo "In Italian: " . $italianGreeting . "\n";

$englishQuestion = $helper->translateFromGerman('Wo ist die Bibliothek?');
echo "In English: " . $englishQuestion;
```

The `translate` method takes:

-   `text` (string): The text to translate.
-   `targetLanguage` (string): The language to translate into (e.g., "Spanish", "fr", "日本語").
-   `sourceLanguage` (string|null): Optional. The language of the original text. If omitted, the LLM attempts auto-detection.
-   `model` (LlmModel|null): Optional. A specific `LlmModel` to use.
-   `options` (array): Optional. Provider-specific options.

It returns the translated text string, or `null` if the translation fails or the result is empty.
