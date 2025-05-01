<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\Contracts\LlmClientInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\Facades\LaravelAi;
use Bramato\LaravelAi\Models\LlmModel;
use InvalidArgumentException;

class ChatService
{
    protected LlmClientInterface $client;

    protected array $history = [];

    protected ?string $systemMessage = null;

    protected array $options = [];

    protected ?string $sessionProvider = null;

    protected ?string $sessionModel = null;

    protected bool $sessionJsonMode = false; // Flag for JSON mode activation

    public function __construct(LlmClientInterface $client)
    {
        $this->client = $client;
        // Potremmo voler impostare il provider/modello/opzioni di default qui,
        // leggendoli dalla configurazione o dall'istanza $client iniettata.
    }

    /**
     * Start a new chat session (static factory method).
     *
     * @param  string  $initialPrompt  The first user message.
     * @param  string|null  $systemMessage  Optional system message for the session.
     * @param  LlmModel|null  $llmModel  Optional LlmModel instance to define provider and model.
     * @param  array  $options  Optional options override for this session.
     * @param  mixed  $jsonData  Null, bool, array, or JSON string to control JSON mode and provide schema/example.
     *
     * @throws InvalidArgumentException If jsonData is an invalid array or non-JSON string.
     */
    public static function create(
        string $initialPrompt,
        ?string $systemMessage = null,
        ?LlmModel $llmModel = null,
        array $options = [],
        mixed $jsonData = null // Changed parameter name and type
    ): self {
        /** @var ChatService $instance */
        $instance = app(self::class);
        $instance->startSession($initialPrompt, $systemMessage, $llmModel, $options, $jsonData);

        return $instance;
    }

    /**
     * Internal method to initialize session state.
     */
    private function startSession(
        string $initialPrompt,
        ?string $systemMessage = null,
        ?LlmModel $llmModel = null,
        array $options = [],
        mixed $jsonData = null
    ): void {
        // Reset state for a new session
        $this->history = [];
        $this->systemMessage = $systemMessage;
        $this->sessionProvider = $llmModel?->provider;
        $this->sessionModel = $llmModel?->model_id;
        $this->options = $options;
        $this->sessionJsonMode = false; // Reset JSON mode flag
        $promptToUse = $initialPrompt;
        $jsonSchemaString = null;

        // Handle jsonData logic
        if ($jsonData === true) {
            $this->sessionJsonMode = true;
        } elseif (is_array($jsonData)) {
            $encodedJson = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encodedJson === false) {
                throw new InvalidArgumentException('Failed to encode array to JSON for jsonData.');
            }
            $jsonSchemaString = $encodedJson;
            $this->sessionJsonMode = true;
        } elseif (is_string($jsonData)) {
            // Validate if the string is valid JSON
            json_decode($jsonData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('The provided jsonData string is not valid JSON.');
            }
            $jsonSchemaString = $jsonData;
            $this->sessionJsonMode = true;
        } // null or false leaves sessionJsonMode as false

        // Append schema to prompt if available
        if ($this->sessionJsonMode && $jsonSchemaString) {
            $promptToUse .= "\n\nPlease provide the response strictly in JSON format matching the following structure:\n```json\n".$jsonSchemaString."\n```";
        }

        // Add the potentially modified initial user prompt
        $this->addMessage('user', $promptToUse);
    }

    /**
     * Sends the current conversation history to the LLM and returns the response.
     *
     * Automatically appends the assistant's response to the history.
     *
     * @throws \RuntimeException If the history is empty or doesn't end with a user message.
     */
    public function getResponse(): ChatResponse
    {
        if (empty($this->history)) {
            throw new \RuntimeException('Cannot get response with empty history. Use create() or addMessage() first.');
        }

        $lastMessage = end($this->history);
        if ($lastMessage['role'] !== 'user') {
            throw new \RuntimeException('The last message in history must be from the user to get a response.');
        }

        // Prepare request data
        $currentPrompt = $lastMessage['content'];
        $historyContext = array_slice($this->history, 0, -1);
        $requestOptions = $this->options;

        // Add session-specific model to options ONLY if it was explicitly set for the session
        // The default client already has its own default model configured.
        if ($this->sessionModel) {
            $requestOptions['model'] = $this->sessionModel;
        }

        $request = new ChatRequest([
            'prompt' => $currentPrompt,
            'history' => $historyContext,
            'systemMessage' => $this->systemMessage,
            'options' => $requestOptions,
            'jsonMode' => $this->sessionJsonMode, // Use the internal flag
        ]);

        // Determine which client instance to use
        $clientToUse = $this->client;
        if ($this->sessionProvider) {
            $clientToUse = LaravelAi::provider($this->sessionProvider);
        }

        // Get response from LLM
        $response = $clientToUse->chat($request);

        // Add assistant response to history
        if ($response->content) {
            $this->addMessage('assistant', $response->content);
        }

        return $response;
    }

    /**
     * Add a message to the current chat session's history.
     *
     * @param  string  $role  The role ('user' or 'assistant').
     * @param  string  $content  The message content.
     *
     * @throws InvalidArgumentException If the role is invalid.
     */
    public function addMessage(string $role, string $content): self
    {
        if (! in_array($role, ['user', 'assistant'])) {
            throw new InvalidArgumentException('Invalid role specified. Must be \'user\' or \'assistant\'.');
        }

        $this->history[] = ['role' => $role, 'content' => $content];

        return $this;
    }

    // -----------------------------------------
    // Optional Helper Methods
    // -----------------------------------------

    /**
     * Get the current conversation history.
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Set the provider for the current session, overriding the default client's provider.
     */
    public function setProvider(string $provider): self
    {
        $this->sessionProvider = $provider;

        return $this;
    }

    /**
     * Set the model for the current session, overriding the default client's model.
     */
    public function setModel(string $model): self
    {
        $this->sessionModel = $model;

        return $this;
    }

    /**
     * Set the options for the current session, overriding the default client's options.
     *
     * @param  bool  $merge  If true, merge with existing options, otherwise replace.
     */
    public function setOptions(array $options, bool $merge = false): self
    {
        $this->options = $merge ? array_merge($this->options, $options) : $options;

        return $this;
    }

    /**
     * Clear the current conversation history.
     */
    public function clearHistory(): self
    {
        $this->history = [];

        // Consider if system message and other session settings should also be cleared.
        // For now, only clearing history.
        return $this;
    }
}
