<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Exceptions\Conversations\SettingsOverrideValueRefusedException;

/**
 * The compaction knobs an operator passes for one console run, parsed once: the threshold and the four counts of the
 * degenerate fallback of {@see CompactionSettings}. Null = not passed (the conversation keeps what it has). Never
 * persisted itself; {@see applyTo()} writes the passed values onto the settings of a conversation.
 *
 * A value that is not a whole number, or below the smallest value its knob allows ({@see MINIMUM_VALUES_BY_FIELD}),
 * is refused: a typing error must not become a threshold of 0.
 *
 * @phpstan-consistent-constructor
 */
class CompactionSettingsOverride extends ValueObject
{
    /** @var int[] The smallest value each knob accepts, by field name of {@see CompactionSettings} */
    public const array MINIMUM_VALUES_BY_FIELD = [
        'triggerTokens' => 1,
        'keepRecentMessages' => 0,
        'segmentMessages' => 1,
        'tierFanIn' => 2,
        'levelRetention' => 0,
    ];

    /** @var int|null {@see CompactionSettings::$triggerTokens}; null = not passed */
    public ?int $triggerTokens = null;

    /** @var int|null {@see CompactionSettings::$keepRecentMessages}; null = not passed */
    public ?int $keepRecentMessages = null;

    /** @var int|null {@see CompactionSettings::$segmentMessages}; null = not passed */
    public ?int $segmentMessages = null;

    /** @var int|null {@see CompactionSettings::$tierFanIn}; null = not passed */
    public ?int $tierFanIn = null;

    /** @var int|null {@see CompactionSettings::$levelRetention}; null = not passed */
    public ?int $levelRetention = null;

    /**
     * Parses the raw option values (null or blank = not passed).
     *
     * @param string|null $triggerTokens
     * @param string|null $keepRecentMessages
     * @param string|null $segmentMessages
     * @param string|null $tierFanIn
     * @param string|null $levelRetention
     * @return static|null null when no value was passed
     * @throws SettingsOverrideValueRefusedException when a value is no whole number or below its minimum
     */
    public static function fromRawValues(
        ?string $triggerTokens = null,
        ?string $keepRecentMessages = null,
        ?string $segmentMessages = null,
        ?string $tierFanIn = null,
        ?string $levelRetention = null
    ): ?static {
        $compactionSettingsOverride = new static();
        $compactionSettingsOverride->triggerTokens = static::parseCount('triggerTokens', $triggerTokens);
        $compactionSettingsOverride->keepRecentMessages = static::parseCount('keepRecentMessages', $keepRecentMessages);
        $compactionSettingsOverride->segmentMessages = static::parseCount('segmentMessages', $segmentMessages);
        $compactionSettingsOverride->tierFanIn = static::parseCount('tierFanIn', $tierFanIn);
        $compactionSettingsOverride->levelRetention = static::parseCount('levelRetention', $levelRetention);
        return $compactionSettingsOverride->isEmpty() ? null : $compactionSettingsOverride;
    }

    /**
     * Whether no knob was passed.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->triggerTokens === null && $this->keepRecentMessages === null && $this->segmentMessages === null
            && $this->tierFanIn === null && $this->levelRetention === null;
    }

    /**
     * A copy of `$compactionSettings` with the passed knobs written onto it; every other field keeps its value (merge,
     * not replace: a knob that was not passed is never cleared). Without settings a new {@see CompactionSettings}
     * carries the passed knobs only. The object passed in is never changed, so a caller can still compare it with the
     * result.
     *
     * @param CompactionSettings|null $baseCompactionSettings the settings to start from (not changed), or null
     * @return CompactionSettings a new settings object
     */
    public function applyTo(?CompactionSettings $baseCompactionSettings): CompactionSettings
    {
        $compactionSettings = $baseCompactionSettings !== null ? clone $baseCompactionSettings : CompactionSettings::newInstance();
        if ($this->triggerTokens !== null) {
            $compactionSettings->triggerTokens = $this->triggerTokens;
        }
        if ($this->keepRecentMessages !== null) {
            $compactionSettings->keepRecentMessages = $this->keepRecentMessages;
        }
        if ($this->segmentMessages !== null) {
            $compactionSettings->segmentMessages = $this->segmentMessages;
        }
        if ($this->tierFanIn !== null) {
            $compactionSettings->tierFanIn = $this->tierFanIn;
        }
        if ($this->levelRetention !== null) {
            $compactionSettings->levelRetention = $this->levelRetention;
        }
        return $compactionSettings;
    }

    /**
     * @param string $fieldName a key of {@see MINIMUM_VALUES_BY_FIELD}
     * @param string|null $rawValue
     * @return int|null null = not passed
     * @throws SettingsOverrideValueRefusedException
     */
    protected static function parseCount(string $fieldName, ?string $rawValue): ?int
    {
        $trimmedValue = trim((string)$rawValue);
        if ($trimmedValue === '') {
            return null;
        }
        $minimumValue = static::MINIMUM_VALUES_BY_FIELD[$fieldName];
        if (!ctype_digit($trimmedValue) || (int)$trimmedValue < $minimumValue) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, "must be a whole number of at least {$minimumValue}, got '{$trimmedValue}'");
        }
        $digitsWithoutLeadingZeros = ltrim($trimmedValue, '0') === '' ? '0' : ltrim($trimmedValue, '0');
        if ((string)(int)$digitsWithoutLeadingZeros !== $digitsWithoutLeadingZeros) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, "is too large, got '{$trimmedValue}'");
        }
        return (int)$trimmedValue;
    }
}
