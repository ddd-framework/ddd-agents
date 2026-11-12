<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE switch row value the admin sent, read into the stored tri-state: whether the text is a valid switch value and the
 * value to store (null clears the row). Built by {@see RunSettingsOverride::parseSwitchRow()}; the gated writer
 * {@see RunSettings} refuses an invalid one.
 *
 * @phpstan-consistent-constructor
 */
class RunSettingsSwitchRow extends ValueObject
{
    /** @var bool Whether the text is `inherit` or one of the yes / no spellings */
    public bool $isValid = false;

    /** @var bool|null The value to store: true / false, or null to clear the row (also null when invalid) */
    public ?bool $storedValue = null;

    /**
     * @param bool $isValid
     * @param bool|null $storedValue
     * @return static
     */
    public static function forValidityAndStoredValue(bool $isValid, ?bool $storedValue): static
    {
        $runSettingsSwitchRow = new static();
        $runSettingsSwitchRow->isValid = $isValid;
        $runSettingsSwitchRow->storedValue = $storedValue;
        return $runSettingsSwitchRow;
    }
}
