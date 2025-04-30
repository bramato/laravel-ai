# Changelog

All notable changes to `bramato/laravel-ai` will be documented in this file.

## [1.1.0] - 2025-XX-XX

### Added

-   **LlmModel Eloquent Model:** Introduced `Bramato\LaravelAi\Models\LlmModel` using `calebporzio/sushi` to list available LLM models and their capabilities (`provider`, `model_id`, `context_window`, `json_mode`, `supports_vision`, `max_output_tokens`).
-   **ChatService:** Added `Bramato\LaravelAi\Services\ChatService` for managing stateful multi-turn conversations.
    -   Handles internal history management.
    -   Static `create()` method for easy session initialization.
    -   Supports setting provider/model via `LlmModel` instance for the session.
    -   Improved JSON mode handling via `jsonData` parameter in `create()` (supports boolean, array schema, or JSON string schema).
    -   Includes helper methods (`getHistory`, `setProvider`, `setModel`, `setOptions`, `clearHistory`).
-   Feature tests for `LlmModel` (`tests/Unit/LlmModelTest.php`).
-   Feature tests for `ChatService` (`tests/Feature/ChatServiceTest.php`) using Mockery.
-   Documentation sections in `README.md` for `LlmModel` and `ChatService`.

## [1.0.0] - 2025-XX-XX

### Added

-   Initial release of the `bramato/laravel-ai` package.
-   Unified `LlmClientInterface` and `LaravelAi` Facade for interacting with LLM chat APIs.
-   Support for OpenAI, Google Gemini, Anthropic Claude, and DeepSeek providers.
-   Configuration file (`config/laravel-ai.php`) for API keys, models, and options.
-   `ChatRequest` and `ChatResponse` DTOs using `wendelladriel/laravel-validated-dto`.
-   Custom exceptions (`LlmApiException`, `AuthenticationException`, `InvalidResponseException`).
-   Support for chat history, system messages, and JSON mode (provider-specific implementation).
-   Basic feature tests for core functionality, providers, DTOs, and JSON mode using Pest and Testbench.
-   Comprehensive `README.md` documentation.
-   `CONTRIBUTING.md`, `LICENSE.md`, `.editorconfig`, `.gitignore`.

<!--
## [Unreleased]

### Added

### Changed

### Deprecated

### Removed

### Fixed

### Security
-->
