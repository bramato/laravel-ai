<?php

namespace Bramato\LaravelAi\Contracts;

use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Exceptions\AuthenticationException;
use Bramato\LaravelAi\Exceptions\InvalidResponseException;
use Bramato\LaravelAi\Exceptions\LlmApiException;

// use Bramato\LaravelAi\Exceptions\LlmApiException; // Exception to be created later

/**
 * Defines the contract for LLM client implementations.
 *
 * All concrete clients (OpenAI, Gemini, Claude, DeepSeek) must implement this interface.
 */
interface LlmClientInterface
{
    /**
     * Sends a chat request to the configured LLM provider.
     *
     * @param  ChatRequest  $request  The DTO containing the request details (prompt, images, history, options, etc.).
     * @return ChatResponse The DTO containing the model's response and metadata.
     *
     * @throws AuthenticationException If authentication fails (e.g., invalid API key).
     * @throws InvalidResponseException If the API response is malformed or blocked.
     * @throws LlmApiException For general API errors (e.g., rate limits, server errors, bad requests).
     * @throws \Throwable For unexpected errors during processing.
     */
    public function chat(ChatRequest $request): ChatResponse;

    /*
    // Alternative or future method for explicitly requesting JSON output
    // Could also be handled by an option within ChatRequest
    public function chatWithJson(ChatRequest $request): ChatResponse; // Or a dedicated JsonResponse DTO
    */

    /*
    // Future consideration: Dedicated method or option for streaming responses?
    public function streamChat(ChatRequest $request): iterable;
    */

    /*
    // Future consideration: Explicit support for tool/function calling?
    // Requires significant changes to DTOs and client logic.
    */
}
