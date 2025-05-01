# Changelog

All notable changes to `bramato/laravel-ai` will be documented in this file.

## [1.3.0] - 2025-XX-XX

### Added

-   **Helper Services:** Introduced dedicated services for common AI tasks:
    -   `ClassificationService`: Classifies text into predefined categories.
    -   `SummarizationService`: Generates text summaries with optional format and length constraints.
    -   `TranslationService`: Provides simple text translation with optional source language detection.
    -   `MultiTranslationService`: Translates text into multiple target languages simultaneously using a single LLM call (requires JSON mode support).
    -   `ImageDescriptionService`: Generates descriptions for images (from path, URL, or UploadedFile) using OpenAI Vision.
-   **BaseAiService:** Added an abstract base class for common service dependencies.
-   **Language Enum:** Created `Enums\Language` for standardized language codes.
-   **New DTOs:** Added `MultiTranslateResponseDto` and `ImageDescriptionResponseDto`.
-   **Image Support Core:**
    -   Added `images` property to `ChatRequest` DTO.
    -   Modified `OpenAiClient` to handle image inputs (URLs and base64 data URIs) for vision models.
-   **Service Interfaces:** Added `ImageDescriptionServiceInterface`.
-   Feature tests for `ClassificationService`, `SummarizationService`, `TranslationService`, `MultiTranslationService`, and `ImageDescriptionService`.
-   Documentation sections in `README.md` for all new services.

### Changed

-   `LlmClientInterface::chat()` PHPDoc updated to reflect `images` support in `ChatRequest`.
-   Services (`ClassificationService`, `SummarizationService`, `TranslationService`) now extend `BaseAiService`.

## [1.2.0] - 2025-XX-XX

### Added

-   **Simple Chat Helpers:** Implemented `ask()` and `askWithSystem()` methods on `LaravelAiManager` (accessible via `LaravelAi` Facade) for quick, stateless chat interactions returning only the content string.
-   **JSON Extraction Helper:** Implemented `extractJson()` method on `LaravelAiManager` (accessible via `LaravelAi` Facade) to easily extract structured JSON data from text using an LLM.
-   Feature tests for Simple Chat Helpers (`SimpleChatHelperTest.php`) and JSON Extraction Helper (`JsonExtractionHelperTest.php`).
-   Documentation sections in `README.md` for the new helper methods.

## [1.1.0] - 2025-XX-XX

### Added

-   **LlmModel Eloquent Model:** Introduced `Bramato\LaravelAi\Models\LlmModel` using `calebporzio/sushi` to list available LLM models and their capabilities (`provider`, `model_id`, `context_window`, `json_mode`, `supports_vision`, `max_output_tokens`, `flagship`). Also added static helper methods (`openAiFlagship()`, `geminiFlagship()`, `claudeFlagship()`, `deepSeekFlagship()`) to retrieve the designated flagship model for each provider.
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
