<?php

namespace Bramato\LaravelAi\Models;

use Illuminate\Database\Eloquent\Model;
use Sushi\Sushi as SushiSushi;

/**
 * Represents an LLM model available through the package.
 *
 * This model uses Sushi to provide an Eloquent-like interface to a static array of model data.
 * It includes information about provider, model ID, capabilities, and flags.
 *
 * @property string $provider The provider key (e.g., 'openai', 'google').
 * @property string $model_id The unique model identifier used in API calls.
 * @property string $description A brief description of the model.
 * @property int $context_window The maximum number of tokens in the context window.
 * @property bool $json_mode Indicates if the model supports a dedicated JSON output mode.
 * @property bool $supports_vision Indicates if the model can process image input.
 * @property int $max_output_tokens The maximum number of tokens the model can generate.
 * @property bool $flagship Indicates if this model is considered a primary model for the provider.
 */
class LlmModel extends Model
{
    use SushiSushi;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider',
        'model_id',
        'description',
        'context_window',
        'json_mode',
        'supports_vision',
        'max_output_tokens',
        'flagship',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, bool|null>
     */
    protected $attributes = [
        'json_mode' => false,
        'supports_vision' => false,
        'flagship' => false,
    ];

    /**
     * The data for the models.
     *
     * This array acts as the "database table" for the Sushi model.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $rows = [
        // OpenAI Models
        [
            'provider' => 'openai',
            'model_id' => 'gpt-4.1',
            'description' => 'Modello di punta per compiti complessi, coding, ragionamento long-context',
            'context_window' => 1047576,
            'json_mode' => true, // Function Calling (Strict), Structured Outputs (Chat Completions)
            'supports_vision' => true, // Solo Input
            'max_output_tokens' => 32768,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'gpt-4.1-mini',
            'description' => 'Mid-size, prestazioni/costo bilanciati',
            'context_window' => 1047576,
            'json_mode' => true, // Function Calling (Strict), Structured Outputs (Chat Completions)
            'supports_vision' => true, // Solo Input
            'max_output_tokens' => 32768,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'gpt-4.1-nano',
            'description' => 'Modello 4.1 più veloce ed economico, per compiti a bassa latenza',
            'context_window' => 1047576,
            'json_mode' => true, // Function Calling (Strict), Structured Outputs (Chat Completions)
            'supports_vision' => true, // Solo Input
            'max_output_tokens' => 32768,
            'flagship' => true,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'description' => 'Modello di punta multimodale, versatile, alta intelligenza',
            'context_window' => 128000,
            'json_mode' => true, // JSON Object mode, Structured Outputs via json_schema, Function Calling
            'supports_vision' => true, // Input/Output via tools
            'max_output_tokens' => 16384,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'gpt-4o-mini',
            'description' => 'Modello multimodale veloce, economico, capace, sostituisce GPT-3.5 Turbo',
            'context_window' => 128000,
            'json_mode' => true, // JSON Object mode, Structured Outputs via json_schema, Function Calling
            'supports_vision' => true, // Input/Output via tools
            'max_output_tokens' => 16384,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'o4-mini',
            'description' => 'Modello di ragionamento (via Responses API), ragionamento migliorato, output strutturati',
            'context_window' => 200000, // Input only
            'json_mode' => true, // Structured Outputs, Function Calling
            'supports_vision' => true, // Solo Input
            'max_output_tokens' => 100000,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'o3-mini',
            'description' => 'Modello di ragionamento (via Responses API), ragionamento migliorato, output strutturati, solo testo',
            'context_window' => 200000, // Input only
            'json_mode' => true, // Structured Outputs, Function Calling
            'supports_vision' => false,
            'max_output_tokens' => 100000,
            'flagship' => false,
        ],
        [
            'provider' => 'openai',
            'model_id' => 'gpt-3.5-turbo',
            'description' => 'Modello legacy, ottimizzato per chat, conveniente',
            'context_window' => 16385,
            'json_mode' => true, // JSON Object mode, Function Calling
            'supports_vision' => false,
            'max_output_tokens' => 4096,
            'flagship' => false,
        ],

        // Google Gemini Models
        [
            'provider' => 'google',
            'model_id' => 'gemini-2.5-pro-preview',
            'description' => 'Ragionamento più avanzato, comprensione multimodale, coding',
            'context_window' => 1048576,
            'json_mode' => true, // Structured Outputs
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo Out
            'max_output_tokens' => 65536,
            'flagship' => true,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-2.5-flash-preview',
            'description' => 'Pensiero adattivo, efficienza dei costi, multimodale',
            'context_window' => 1048576,
            'json_mode' => true, // Structured Outputs
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo Out
            'max_output_tokens' => 65536,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-2.0-flash',
            'description' => 'Workhorse, velocità, generazione multimodale',
            'context_window' => 1048576,
            'json_mode' => true, // Structured Outputs
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo, Immagine(exp), Audio(soon) Out
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-2.0-flash-lite',
            'description' => 'Efficienza dei costi, bassa latenza',
            'context_window' => 1048576,
            'json_mode' => true, // Structured Outputs
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo Out
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-1.5-pro',
            'description' => 'Ragionamento complesso, long context (fino a 2M)',
            'context_window' => 1048576, // Standard, up to 2,097,152
            'json_mode' => true, // JSON mode, JSON schema
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo/Codice/JSON Out
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-1.5-flash',
            'description' => 'Veloce, versatile, multimodale, 1M context',
            'context_window' => 1048576,
            'json_mode' => true, // JSON mode, JSON schema
            'supports_vision' => true, // Audio, Immagine, Video, Testo In; Testo/Codice/JSON Out
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-1.0-pro',
            'description' => 'Modello legacy per chat e generazione testo/codice',
            'context_window' => 32760, // Input approx
            'json_mode' => false, // Non ufficiale, inaffidabile
            'supports_vision' => false, // Solo Testo/Codice
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'google',
            'model_id' => 'gemini-1.0-pro-vision',
            'description' => 'Modello legacy input multimodale per comprensione visiva, output testo/codice',
            'context_window' => 12288, // Input
            'json_mode' => false,
            'supports_vision' => true, // Immagine, Frame Video, Testo In; Testo/Codice Out
            'max_output_tokens' => 4096,
            'flagship' => false,
        ],

        // DeepSeek Models
        [
            'provider' => 'deepseek',
            'model_id' => 'deepseek-chat',
            'description' => 'Modello di chat generale, punta a DeepSeek-V3',
            'context_window' => 64000, // API limit
            'json_mode' => false, // Non Specificato/No (API)
            'supports_vision' => false, // No (API)
            'max_output_tokens' => 8192, // API limit (default 4k)
            'flagship' => true,
        ],
        [
            'provider' => 'deepseek',
            'model_id' => 'deepseek-reasoner',
            'description' => 'Modello focalizzato sul ragionamento, punta a DeepSeek-R1',
            'context_window' => 64000, // API limit
            'json_mode' => false, // Non Specificato/No (API)
            'supports_vision' => false, // No (API)
            'max_output_tokens' => 8192, // API limit (default 4k, + 32k CoT)
            'flagship' => false,
        ],

        // Anthropic Claude Models
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3.7-sonnet-20250219',
            'description' => 'Modello più intelligente, capacità di pensiero esteso',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 64000,
            'flagship' => true,
        ],
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3.5-sonnet-20241022',
            'description' => 'Precedente modello di punta, bilanciato',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3.5-haiku-20241022',
            'description' => 'Modello più veloce ed economico',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 8192,
            'flagship' => false,
        ],
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3-opus-20240229',
            'description' => 'Potente, per compiti complessi',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 4096,
            'flagship' => false,
        ],
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3-sonnet-20240229',
            'description' => 'Bilanciato v3',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 4096,
            'flagship' => false,
        ],
        [
            'provider' => 'anthropic',
            'model_id' => 'claude-3-haiku-20240307',
            'description' => 'Veloce v3',
            'context_window' => 200000,
            'json_mode' => true, // Via Tool Use
            'supports_vision' => true,
            'max_output_tokens' => 4096,
            'flagship' => false,
        ],
    ];

    /**
     * The data types for the attributes.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'context_window' => 'integer',
        'json_mode' => 'boolean',
        'supports_vision' => 'boolean',
        'max_output_tokens' => 'integer',
        'flagship' => 'boolean',
    ];

    /**
     * Get the table schema represented by this model's properties.
     * Used by Sushi for proper type handling.
     *
     * @return array<string, string>
     */
    public function getSchema(): array
    {
        return [
            'provider' => 'string',
            'model_id' => 'string',
            'description' => 'string',
            'context_window' => 'integer',
            'json_mode' => 'boolean',
            'supports_vision' => 'boolean',
            'max_output_tokens' => 'integer',
            'flagship' => 'boolean',
        ];
    }

    /**
     * Get the flagship OpenAI model.
     *
     * @return static|null The flagship LlmModel instance for OpenAI, or null if not found.
     */
    public static function openAiFlagship(): ?self
    {
        return static::where('provider', 'openai')->where('flagship', true)->first();
    }

    /**
     * Get the flagship Google Gemini model.
     *
     * @return static|null The flagship LlmModel instance for Google, or null if not found.
     */
    public static function geminiFlagship(): ?self
    {
        return static::where('provider', 'google')->where('flagship', true)->first();
    }

    /**
     * Get the flagship Anthropic Claude model.
     *
     * @return static|null The flagship LlmModel instance for Anthropic, or null if not found.
     */
    public static function claudeFlagship(): ?self
    {
        return static::where('provider', 'anthropic')->where('flagship', true)->first();
    }

    /**
     * Get the flagship DeepSeek model.
     *
     * @return static|null The flagship LlmModel instance for DeepSeek, or null if not found.
     */
    public static function deepSeekFlagship(): ?self
    {
        return static::where('provider', 'deepseek')->where('flagship', true)->first();
    }
}
