<?php

namespace Bramato\LaravelAi\Exceptions;

/**
 * Base Exception for Laravel AI package errors.
 *
 * Represents general errors encountered during API interactions or
 * within the package logic itself.
 */
class LlmApiException extends \Exception
{
    // Base exception for package-specific API errors.
    // Can be extended for more specific errors like AuthenticationException,
    // InvalidResponseException, RateLimitException, etc.
}
