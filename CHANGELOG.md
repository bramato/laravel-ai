# Changelog

All notable changes to `bramato/laravel-ai` will be documented in this file.

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
