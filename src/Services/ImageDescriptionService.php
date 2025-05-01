<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\Clients\OpenAiClient; // Specific dependency for now
use Bramato\LaravelAi\Contracts\ImageDescriptionServiceInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ImageDescriptionResponseDto;
use Bramato\LaravelAi\Models\LlmModel;
use Exception;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\UploadedFile; // Use Laravel File facade
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Service for generating image descriptions using OpenAI Vision API.
 */
class ImageDescriptionService implements ImageDescriptionServiceInterface
{
    protected OpenAiClient $openAiClient;

    protected HttpClientFactory $httpFactory;

    /**
     * Constructor.
     *
     * @param  OpenAiClient  $openAiClient  Instance of the OpenAI client, resolved specifically via service container.
     * @param  HttpClientFactory  $httpFactory  Factory for making HTTP requests.
     */
    public function __construct(OpenAiClient $openAiClient, HttpClientFactory $httpFactory)
    {
        $this->openAiClient = $openAiClient;
        $this->httpFactory = $httpFactory;
    }

    /**
     * {@inheritdoc}
     *
     * Processes the image source, prepares a ChatRequest with the image data URI,
     * and calls the OpenAI client to generate a description.
     *
     * @throws InvalidArgumentException If the image source is invalid, not found, too large, or has an unsupported MIME type.
     * @throws Exception If image download fails or an unexpected error occurs during the OpenAI API call.
     */
    public function describe(
        string|UploadedFile $imageSource,
        ?string $prompt = null,
        ?LlmModel $model = null, // Note: Currently OpenAI client handles model selection internally
        array $options = []
    ): ?ImageDescriptionResponseDto {
        try {
            $imageDataUri = $this->processImageSource($imageSource);
            if (! $imageDataUri) {
                return $this->fallbackResponse('Failed to process image source.');
            }

            $finalPrompt = $prompt ?? 'Describe this image.'; // Default prompt

            // Prepare request for OpenAI client
            $chatRequest = new ChatRequest([
                'prompt' => $finalPrompt,
                'images' => [$imageDataUri],
                // If a specific model was requested, pass it in options
                // The OpenAIClient will handle vision model selection/validation
                // based on the presence of images and this option.
                // We don't explicitly *select* the model here, but pass the user's preference.
                'options' => $model ? array_merge($options, ['model' => $model->model_id]) : $options,
            ]);

            // Call OpenAI client
            $response = $this->openAiClient->chat($chatRequest);

            // Basic description from content
            $description = $response->content ?? null;

            if (empty($description)) {
                return $this->fallbackResponse('Could not generate a description.');
            }

            // TODO: Potentially parse description for confidence/tags if instructed in prompt
            // For now, just return the description.

            return new ImageDescriptionResponseDto([
                'description' => trim($description),
                // confidence and tags are not directly provided by OpenAI Chat API
            ]);
        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors
            throw $e;
        } catch (Exception $e) {
            report($e);

            return $this->fallbackResponse('An error occurred while describing the image: ' . $e->getMessage());
        }
    }

    /**
     * Processes the image source (path, URL, UploadedFile) into a base64 data URI suitable for the vision API.
     *
     * @param  string|UploadedFile  $source  The image source to process.
     * @return string The base64 data URI (e.g., "data:image/jpeg;base64,...").
     *
     * @throws InvalidArgumentException If the source type is invalid, file is not readable, MIME type is unsupported, or size limit is exceeded.
     * @throws Exception If downloading the image from a URL fails.
     */
    private function processImageSource(string|UploadedFile $source): ?string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']; // Common types supported by vision models
        $maxSize = 10 * 1024 * 1024; // Example: 10MB limit

        if ($source instanceof UploadedFile) {
            if (! $source->isValid()) {
                throw new InvalidArgumentException('Invalid uploaded file provided.');
            }
            $mime = $source->getMimeType();
            $size = $source->getSize();
            $content = File::get($source->getRealPath());
        } elseif (is_string($source) && filter_var($source, FILTER_VALIDATE_URL)) {
            // Handle URL
            try {
                $response = $this->httpFactory->timeout(30)->get($source);
                if ($response->failed()) {
                    throw new Exception('Failed to download image from URL: ' . $source . ' (Status: ' . $response->status() . ')');
                }
                $mime = $response->header('Content-Type');
                $content = $response->body();
                $size = strlen($content);
            } catch (Exception $e) {
                throw new Exception("Error downloading image from URL {$source}: " . $e->getMessage(), 0, $e);
            }
        } elseif (is_string($source) && File::exists($source) && File::isReadable($source)) {
            // Handle local path
            $mime = File::mimeType($source);
            $content = File::get($source);
            $size = File::size($source);
        } else {
            throw new InvalidArgumentException('Invalid image source provided. Must be a valid URL, readable file path, or UploadedFile instance.');
        }

        // Validate MIME type and size
        if (! $mime || ! in_array(strtolower($mime), $allowedMimes)) {
            throw new InvalidArgumentException("Unsupported image MIME type: {$mime}. Supported types: " . implode(', ', $allowedMimes));
        }
        if ($size > $maxSize) {
            throw new InvalidArgumentException("Image size ({$size} bytes) exceeds the maximum allowed limit ({$maxSize} bytes).");
        }

        // Encode to base64 data URI
        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    /**
     * Creates a fallback ImageDescriptionResponseDto and reports the error message.
     *
     * This is used when image processing or the AI call fails, providing a default response.
     *
     * @param  string  $message  Error message for logging/reporting via Laravel's report() helper.
     * @return ImageDescriptionResponseDto The DTO containing the default fallback description.
     */
    private function fallbackResponse(string $message): ImageDescriptionResponseDto
    {
        report('Image Description Service: ' . $message);

        return new ImageDescriptionResponseDto([ // Returns DTO with default description
            // Default values from DTO will be used
        ]);
    }
}
