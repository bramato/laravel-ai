<?php

namespace Bramato\LaravelAi\Contracts;

use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
// use Bramato\LaravelAi\Exceptions\LlmApiException; // Exception to be created later

interface LlmClientInterface
{
    /**
     * Sends a chat request and returns the text response.
     *
     * @param ChatRequest $request The DTO containing the request data.
     * @return ChatResponse The DTO containing the model's response.
     * @throws \Bramato\LaravelAi\Exceptions\LlmApiException On API errors.
     */
    public function chat(ChatRequest $request): ChatResponse;

    /*
    // Alternative or future method for explicitly requesting JSON output
    // Could also be handled by an option within ChatRequest
    public function chatWithJson(ChatRequest $request): ChatResponse; // Or a dedicated JsonResponse DTO
    */
}
