<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Languages;

use DDD\Infrastructure\Libs\Config;
use Locale;

/**
 * The English name of a language by its ISO 639-1 code ("de" → "German"), as the agent's prompts and cues name the
 * reply language in words. Reads the application's table under the config key {@see CONFIG_KEY_LANGUAGE_NAMES}
 * (`<code> => ['englishName' => …, 'nativeName' => …]`); only when that key is absent it asks PHP's ICU data. Static
 * helper without state.
 */
class AgentLanguageNames
{
    /** The config key of the language table (`config/app/Common/languageNames.php`, key `languages`). */
    public const string CONFIG_KEY_LANGUAGE_NAMES = 'Common.languageNames.languages';

    /**
     * The English name of `$languageCode`, or null for an empty code or a code the configured table does not know
     * (callers fall back to the code). Region suffixes are ignored ("de-DE" / "de_DE" → "de").
     *
     * @param string|null $languageCode
     * @return string|null
     */
    public static function englishNameForLanguageCode(?string $languageCode): ?string
    {
        $languageNames = Config::get(static::CONFIG_KEY_LANGUAGE_NAMES);
        return static::findEnglishNameForLanguageCode($languageCode, is_array($languageNames) ? $languageNames : null);
    }

    /**
     * The English name of `$languageCode` in `$languageNames` (the configured table); with no table (null) the ICU
     * display name in English, null when ICU knows the code only by the code itself. Pure.
     *
     * @param string|null $languageCode
     * @param array<string, array<string, string>>|null $languageNames
     * @return string|null
     */
    public static function findEnglishNameForLanguageCode(?string $languageCode, ?array $languageNames): ?string
    {
        $normalizedLanguageCode = static::normalizeLanguageCode($languageCode);
        if ($normalizedLanguageCode === null) {
            return null;
        }
        if ($languageNames !== null) {
            return $languageNames[$normalizedLanguageCode]['englishName'] ?? null;
        }
        $displayLanguage = Locale::getDisplayLanguage($normalizedLanguageCode, 'en');
        return $displayLanguage !== '' && $displayLanguage !== $normalizedLanguageCode ? $displayLanguage : null;
    }

    /**
     * The bare ISO 639-1 code of a language or locale code, lower case ("de-DE" / "de_DE" / " DE " → "de"); null for an
     * empty code. Pure.
     *
     * @param string|null $languageCode
     * @return string|null
     */
    public static function normalizeLanguageCode(?string $languageCode): ?string
    {
        $normalizedLanguageCode = strtolower(trim((string)$languageCode));
        if ($normalizedLanguageCode === '') {
            return null;
        }
        return explode('-', str_replace('_', '-', $normalizedLanguageCode))[0];
    }
}
