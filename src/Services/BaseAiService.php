<?php

namespace Bramato\LaravelAi\Services;

use Bramato\LaravelAi\LaravelAiManager;

/**
 * Base class for AI-related services providing common functionality.
 */
abstract class BaseAiService
{
    protected LaravelAiManager $laravelAi;

    /**
     * BaseService constructor.
     *
     * @param LaravelAiManager $laravelAi The main AI manager instance.
     */
    public function __construct(LaravelAiManager $laravelAi)
    {
        $this->laravelAi = $laravelAi;
    }

    // Potential future home for common helper methods (e.g., error handling wrapper)
}
