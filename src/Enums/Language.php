<?php

namespace Bramato\LaravelAi\Enums;

/**
 * Enum representing supported languages with their ISO codes.
 */
enum Language: string
{
    // Add more languages as needed
    case ENGLISH = 'en';
    case ITALIAN = 'it';
    case GERMAN = 'de';
    case FRENCH = 'fr';
    case SPANISH = 'es';

    // Examples with region codes
    case ENGLISH_US = 'en_US';
    case ENGLISH_UK = 'en_GB';
    case ITALIAN_ITALY = 'it_IT';
    case SPANISH_SPAIN = 'es_ES';

    /**
     * Get the ISO language code.
     */
    public function code(): string
    {
        return $this->value;
    }

    /**
     * Try to find a Language case from an ISO code (case-insensitive).
     *
     * @param  string  $code  The ISO code (e.g., 'it', 'en_US').
     * @return self|null Returns the matching Language case or null if not found.
     */
    public static function tryFromCode(string $code): ?self
    {
        $normalizedCode = strtolower(str_replace('-', '_', $code)); // Normalize en-US to en_us
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $normalizedCode) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Get an array of all ISO codes defined in the enum.
     *
     * @return array<int, string>
     */
    public static function allCodes(): array
    {
        return array_column(self::cases(), 'value');
    }
}
