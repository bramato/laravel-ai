<?php

namespace Bramato\LaravelAi\Exceptions;

class LlmApiException extends \Exception
{
    // Base exception for package-specific API errors.
    // Can be extended for more specific errors like AuthenticationException,
    // InvalidResponseException, RateLimitException, etc.
}
