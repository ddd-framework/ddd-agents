<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * A value passed for one knob of a settings override (the context retention, the compaction) is outside what the knob
 * accepts. Carries the field and the reason apart, so a caller that took the value from an option of its own (a
 * console option, a request field) can name that option instead of the field.
 *
 * @phpstan-consistent-constructor
 */
class SettingsOverrideValueRefusedException extends BadRequestException
{
    /** @var string The refused field of the settings */
    public string $refusedFieldName = '';

    /** @var string Why the value was refused, without the field name (e.g. "must be a whole number of at least 2, got '1'") */
    public string $refusalReason = '';

    /**
     * @param string $refusedFieldName the refused field of the settings
     * @param string $refusalReason why the value was refused, without the field name
     * @return static the message reads "<field> <reason>"
     */
    public static function forFieldAndReason(string $refusedFieldName, string $refusalReason): static
    {
        $settingsOverrideValueRefusedException = new static($refusedFieldName . ' ' . $refusalReason);
        $settingsOverrideValueRefusedException->refusedFieldName = $refusedFieldName;
        $settingsOverrideValueRefusedException->refusalReason = $refusalReason;
        return $settingsOverrideValueRefusedException;
    }
}
