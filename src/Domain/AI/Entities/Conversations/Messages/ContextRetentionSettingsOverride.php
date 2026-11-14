<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Exceptions\Conversations\SettingsOverrideValueRefusedException;

/**
 * The context-retention knobs an operator passes for one console run, parsed once: eager tool loading, the unloading
 * unit, the three creation stamps (skill-loading strategy, epoch rebuild on a situation change, state-change
 * delivery) and the eight token and turn thresholds of the eviction. Null = not passed (the conversation keeps what it
 * has). Never persisted itself; {@see applyTo()} writes the passed values onto the settings of a conversation.
 *
 * Every value is checked: a threshold must be a whole number of at least 0, a choice one of the values
 * {@see ContextRetentionSettings} knows. A value outside is refused instead of read as "not passed" or as 0.
 *
 * @phpstan-consistent-constructor
 */
class ContextRetentionSettingsOverride extends ValueObject
{
    /** @var string[] The threshold fields of {@see ContextRetentionSettings} an override may set */
    public const array COUNT_FIELDS = [
        'pressureBudgetTokens',
        'hardCapTokens',
        'referenceResidentTokens',
        'unloadToolAfterUnusedTokens',
        'unloadToolAfterUnusedTurns',
        'unloadSkillAfterUnusedTokens',
        'unloadSkillAfterUnusedTurns',
        'minResidencyTurns',
    ];

    /** @var string The raw value that switches the epoch rebuild on */
    public const string SWITCH_ON = 'on';

    /** @var string The raw value that switches the epoch rebuild off */
    public const string SWITCH_OFF = 'off';

    /** @var bool|null true = load every tool of a skill when the skill loads; null = not passed */
    public ?bool $loadToolsOnSkillLoad = null;

    /** @var string|null {@see ContextRetentionSettings::$unloadGranularity}; null = not passed */
    public ?string $unloadGranularity = null;

    /** @var string|null {@see ContextRetentionSettings::$skillLoadingStrategy}; null = not passed */
    public ?string $skillLoadingStrategy = null;

    /** @var bool|null {@see ContextRetentionSettings::$rebuildEpochOnSituationChange}; null = not passed */
    public ?bool $rebuildEpochOnSituationChange = null;

    /** @var string|null {@see ContextRetentionSettings::$stateChangeDelivery}; null = not passed */
    public ?string $stateChangeDelivery = null;

    /** @var int|null {@see ContextRetentionSettings::$pressureBudgetTokens}; null = not passed */
    public ?int $pressureBudgetTokens = null;

    /** @var int|null {@see ContextRetentionSettings::$hardCapTokens}; null = not passed */
    public ?int $hardCapTokens = null;

    /** @var int|null {@see ContextRetentionSettings::$referenceResidentTokens}; null = not passed */
    public ?int $referenceResidentTokens = null;

    /** @var int|null {@see ContextRetentionSettings::$unloadToolAfterUnusedTokens}; null = not passed */
    public ?int $unloadToolAfterUnusedTokens = null;

    /** @var int|null {@see ContextRetentionSettings::$unloadToolAfterUnusedTurns}; null = not passed */
    public ?int $unloadToolAfterUnusedTurns = null;

    /** @var int|null {@see ContextRetentionSettings::$unloadSkillAfterUnusedTokens}; null = not passed */
    public ?int $unloadSkillAfterUnusedTokens = null;

    /** @var int|null {@see ContextRetentionSettings::$unloadSkillAfterUnusedTurns}; null = not passed */
    public ?int $unloadSkillAfterUnusedTurns = null;

    /** @var int|null {@see ContextRetentionSettings::$minResidencyTurns}; null = not passed */
    public ?int $minResidencyTurns = null;

    /**
     * Parses the raw option values (null or blank = not passed).
     *
     * @param bool $loadToolsOnSkillLoad true = the eager flag was passed
     * @param string|null $unloadGranularity `skill` / `tool`
     * @param string|null $skillLoadingStrategy one of {@see ContextRetentionSettings::SKILL_LOADING_STRATEGIES}
     * @param string|null $rebuildEpochOnSituationChange {@see SWITCH_ON} / {@see SWITCH_OFF}
     * @param string|null $stateChangeDelivery one of {@see ContextRetentionSettings::STATE_CHANGE_DELIVERIES}
     * @param array<string, string|null> $rawCountsByField raw thresholds by a field name of {@see COUNT_FIELDS}
     * @return static|null null when no value was passed
     * @throws SettingsOverrideValueRefusedException when a value is outside what its knob accepts, or a field name is unknown
     */
    public static function fromRawValues(
        bool $loadToolsOnSkillLoad = false,
        ?string $unloadGranularity = null,
        ?string $skillLoadingStrategy = null,
        ?string $rebuildEpochOnSituationChange = null,
        ?string $stateChangeDelivery = null,
        array $rawCountsByField = []
    ): ?static {
        $contextRetentionSettingsOverride = new static();
        $contextRetentionSettingsOverride->loadToolsOnSkillLoad = $loadToolsOnSkillLoad ? true : null;
        $contextRetentionSettingsOverride->unloadGranularity = static::parseChoice(
            'unloadGranularity',
            $unloadGranularity,
            ContextRetentionSettings::canonicalUnloadGranularity($unloadGranularity),
            [ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL, ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL]
        );
        $contextRetentionSettingsOverride->skillLoadingStrategy = static::parseChoice(
            'skillLoadingStrategy',
            $skillLoadingStrategy,
            ContextRetentionSettings::canonicalSkillLoadingStrategy($skillLoadingStrategy),
            ContextRetentionSettings::SKILL_LOADING_STRATEGIES
        );
        $contextRetentionSettingsOverride->rebuildEpochOnSituationChange = static::parseSwitch('rebuildEpochOnSituationChange', $rebuildEpochOnSituationChange);
        $contextRetentionSettingsOverride->stateChangeDelivery = static::parseChoice(
            'stateChangeDelivery',
            $stateChangeDelivery,
            ContextRetentionSettings::canonicalStateChangeDelivery($stateChangeDelivery),
            ContextRetentionSettings::STATE_CHANGE_DELIVERIES
        );
        foreach ($rawCountsByField as $fieldName => $rawCount) {
            if (!in_array($fieldName, static::COUNT_FIELDS, true)) {
                throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, 'is no threshold of the context retention');
            }
            $contextRetentionSettingsOverride->{$fieldName} = static::parseCount($fieldName, $rawCount);
        }
        return $contextRetentionSettingsOverride->isEmpty() ? null : $contextRetentionSettingsOverride;
    }

    /**
     * Whether no knob was passed.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        if ($this->loadToolsOnSkillLoad !== null || $this->unloadGranularity !== null || $this->skillLoadingStrategy !== null
            || $this->rebuildEpochOnSituationChange !== null || $this->stateChangeDelivery !== null) {
            return false;
        }
        foreach (static::COUNT_FIELDS as $fieldName) {
            if ($this->{$fieldName} !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * A copy of `$contextRetentionSettings` with the passed knobs written onto it; every other field keeps its value
     * (merge, not replace). Without settings a new {@see ContextRetentionSettings} carries the passed knobs only. The
     * object passed in is never changed: whether a creation stamp may still change (the skill-loading strategy after a
     * conversation's first model call) is the caller's check, and it compares the stored settings with this result.
     *
     * @param ContextRetentionSettings|null $baseContextRetentionSettings the settings to start from (not changed), or null
     * @return ContextRetentionSettings a new settings object
     */
    public function applyTo(?ContextRetentionSettings $baseContextRetentionSettings): ContextRetentionSettings
    {
        $contextRetentionSettings = $baseContextRetentionSettings !== null ? clone $baseContextRetentionSettings : ContextRetentionSettings::newInstance();
        if ($this->loadToolsOnSkillLoad !== null) {
            $contextRetentionSettings->loadToolsOnSkillLoad = $this->loadToolsOnSkillLoad;
        }
        if ($this->unloadGranularity !== null) {
            $contextRetentionSettings->unloadGranularity = $this->unloadGranularity;
        }
        if ($this->skillLoadingStrategy !== null) {
            $contextRetentionSettings->skillLoadingStrategy = $this->skillLoadingStrategy;
        }
        if ($this->rebuildEpochOnSituationChange !== null) {
            $contextRetentionSettings->rebuildEpochOnSituationChange = $this->rebuildEpochOnSituationChange;
        }
        if ($this->stateChangeDelivery !== null) {
            $contextRetentionSettings->stateChangeDelivery = $this->stateChangeDelivery;
        }
        foreach (static::COUNT_FIELDS as $fieldName) {
            if ($this->{$fieldName} !== null) {
                $contextRetentionSettings->{$fieldName} = $this->{$fieldName};
            }
        }
        return $contextRetentionSettings;
    }

    /**
     * @param string $fieldName
     * @param string|null $rawValue
     * @param string|null $canonicalValue the canonical spelling of `$rawValue`, null when it is no known value
     * @param string[] $acceptedValues
     * @return string|null null = not passed
     * @throws SettingsOverrideValueRefusedException when a value was passed and is not one of `$acceptedValues`
     */
    protected static function parseChoice(string $fieldName, ?string $rawValue, ?string $canonicalValue, array $acceptedValues): ?string
    {
        if (trim((string)$rawValue) === '') {
            return null;
        }
        if ($canonicalValue === null) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, 'must be one of ' . implode(', ', $acceptedValues) . ", got '" . trim((string)$rawValue) . "'");
        }
        return $canonicalValue;
    }

    /**
     * @param string $fieldName
     * @param string|null $rawValue
     * @return bool|null null = not passed
     * @throws SettingsOverrideValueRefusedException when a value was passed and is neither {@see SWITCH_ON} nor {@see SWITCH_OFF}
     */
    protected static function parseSwitch(string $fieldName, ?string $rawValue): ?bool
    {
        $normalizedValue = strtolower(trim((string)$rawValue));
        if ($normalizedValue === '') {
            return null;
        }
        if (!in_array($normalizedValue, [static::SWITCH_ON, static::SWITCH_OFF], true)) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, 'must be ' . static::SWITCH_ON . ' or ' . static::SWITCH_OFF . ", got '" . trim((string)$rawValue) . "'");
        }
        return $normalizedValue === static::SWITCH_ON;
    }

    /**
     * @param string $fieldName
     * @param string|null $rawValue
     * @return int|null null = not passed
     * @throws SettingsOverrideValueRefusedException when a value was passed and is no whole number of at least 0
     */
    protected static function parseCount(string $fieldName, ?string $rawValue): ?int
    {
        $trimmedValue = trim((string)$rawValue);
        if ($trimmedValue === '') {
            return null;
        }
        if (!ctype_digit($trimmedValue)) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, "must be a whole number of at least 0, got '{$trimmedValue}'");
        }
        $digitsWithoutLeadingZeros = ltrim($trimmedValue, '0') === '' ? '0' : ltrim($trimmedValue, '0');
        if ((string)(int)$digitsWithoutLeadingZeros !== $digitsWithoutLeadingZeros) {
            throw SettingsOverrideValueRefusedException::forFieldAndReason($fieldName, "is too large, got '{$trimmedValue}'");
        }
        return (int)$trimmedValue;
    }
}
