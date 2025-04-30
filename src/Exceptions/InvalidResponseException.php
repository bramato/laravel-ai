<?php

namespace Bramato\LaravelAi\Exceptions;

/**
 * Exception for errors related to unexpected or malformed API responses.
 *
 * Thrown when the structure of the API response is not as expected,
 * or when the response indicates blocking due to safety settings, etc.
 */
class InvalidResponseException extends LlmApiException
{
    // Exception for errors related to unexpected or malformed API responses.
}
