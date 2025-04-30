<?php

namespace Bramato\LaravelAi\Exceptions;

/**
 * Exception specifically for API authentication/authorization errors.
 *
 * Thrown when an API request fails due to issues like an invalid API key,
 * insufficient permissions, or other 401/403 errors.
 */
class AuthenticationException extends LlmApiException
{
    // Exception specifically for API authentication errors (e.g., invalid API key).
}
