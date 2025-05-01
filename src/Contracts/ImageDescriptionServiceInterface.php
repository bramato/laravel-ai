<?php

namespace Bramato\LaravelAi\Contracts;

use Bramato\LaravelAi\DTOs\ImageDescriptionResponseDto;
use Bramato\LaravelAi\Models\LlmModel;
use Illuminate\Http\UploadedFile; // Import UploadedFile

/**
 * Interface for the Image Description Service.
 */
interface ImageDescriptionServiceInterface
{
    /**
     * Describes the given image source.
     *
     * @param  string|UploadedFile  $imageSource  Path to a local image, a public URL, or an UploadedFile instance.
     * @param  string|null  $prompt  Optional custom prompt to guide the description (e.g., "Focus on the people in the image").
     * @param  LlmModel|null  $model  Optional: Specific LlmModel to use (must support vision).
     * @param  array  $options  Optional: Provider-specific options (e.g., for OpenAI client).
     * @return ImageDescriptionResponseDto|null The DTO containing the description, or null on failure.
     */
    public function describe(
        string|UploadedFile $imageSource,
        ?string $prompt = null,
        ?LlmModel $model = null,
        array $options = []
    ): ?ImageDescriptionResponseDto;
}
