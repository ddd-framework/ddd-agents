<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Exceptions\Conversations\SettingsOverrideValueRefusedException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The console options of the context-retention knobs (eager tool loading, the unloading unit, the creation stamps of
 * skill loading, epoch rebuild and state-change delivery, the eviction thresholds) and of the compaction knobs, for a
 * command of a {@see \Symfony\Component\Console\Command\Command}. The trait declares the options and reads their raw
 * values into one value object each; parsing, refusal of a bad value and the writing onto a conversation's settings
 * are {@see ContextRetentionSettingsOverride} and {@see CompactionSettingsOverride}.
 *
 * The options `skill-loading-strategy`, `rebuild-epoch-on-situation-change` and `state-change-delivery` have the same
 * names as three arms of {@see AgentEvalArmOptionsTrait}: a command uses one of the two traits for them, never both.
 */
trait ContextRetentionSettingsOptionsTrait
{
    /** @var string The flag that loads every tool of a skill when the skill loads */
    public const string OPTION_LOAD_TOOLS_ON_SKILL_LOAD = 'eager';

    /** @var string The unloading unit */
    public const string OPTION_UNLOAD_GRANULARITY = 'unload-granularity';

    /** @var string The skill-loading strategy */
    public const string OPTION_SKILL_LOADING_STRATEGY = 'skill-loading-strategy';

    /** @var string The epoch rebuild on a situation change */
    public const string OPTION_REBUILD_EPOCH_ON_SITUATION_CHANGE = 'rebuild-epoch-on-situation-change';

    /** @var string The state-change delivery */
    public const string OPTION_STATE_CHANGE_DELIVERY = 'state-change-delivery';

    /** @var string[] The eviction thresholds: option name by field name of {@see ContextRetentionSettingsOverride::COUNT_FIELDS} */
    public const array CONTEXT_RETENTION_COUNT_OPTION_NAMES_BY_FIELD = [
        'pressureBudgetTokens' => 'evict-soft',
        'hardCapTokens' => 'evict-hard',
        'referenceResidentTokens' => 'evict-ref',
        'unloadToolAfterUnusedTokens' => 'evict-tool-tokens',
        'unloadToolAfterUnusedTurns' => 'evict-tool-turns',
        'unloadSkillAfterUnusedTokens' => 'evict-skill-tokens',
        'unloadSkillAfterUnusedTurns' => 'evict-skill-turns',
        'minResidencyTurns' => 'evict-min-residency',
    ];

    /** @var string[] The choice options: option name by field name of {@see ContextRetentionSettingsOverride} */
    public const array CONTEXT_RETENTION_CHOICE_OPTION_NAMES_BY_FIELD = [
        'unloadGranularity' => self::OPTION_UNLOAD_GRANULARITY,
        'skillLoadingStrategy' => self::OPTION_SKILL_LOADING_STRATEGY,
        'rebuildEpochOnSituationChange' => self::OPTION_REBUILD_EPOCH_ON_SITUATION_CHANGE,
        'stateChangeDelivery' => self::OPTION_STATE_CHANGE_DELIVERY,
    ];

    /** @var string The prefix of the compaction options of a command whose own options are about something else */
    public const string COMPACTION_OPTION_PREFIX = 'compact-';

    /** @var string[] The compaction options without prefix, by field name of {@see CompactionSettingsOverride::MINIMUM_VALUES_BY_FIELD} */
    public const array COMPACTION_OPTION_NAMES_BY_FIELD = [
        'triggerTokens' => 'trigger-tokens',
        'keepRecentMessages' => 'keep-messages',
        'segmentMessages' => 'segment-messages',
        'tierFanIn' => 'fan-in',
        'levelRetention' => 'retention',
    ];

    /**
     * Declares the context-retention options. Call it from `configure()`.
     *
     * @return void
     */
    protected function addContextRetentionSettingsOptions(): void
    {
        $this
            ->addOption(self::OPTION_LOAD_TOOLS_ON_SKILL_LOAD, null, InputOption::VALUE_NONE, 'Load every tool of a skill when the skill loads (the carried load grows fast, so eviction starts sooner); the default loads a tool on first use')
            ->addOption('evict-soft', null, InputOption::VALUE_REQUIRED, 'pressureBudgetTokens: the soft eviction target in tokens (default ' . LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS . ')')
            ->addOption('evict-hard', null, InputOption::VALUE_REQUIRED, 'hardCapTokens: the hard ceiling in tokens (default ' . LoadedCapability::DEFAULT_HARD_CAP_TOKENS . ')')
            ->addOption('evict-ref', null, InputOption::VALUE_REQUIRED, 'referenceResidentTokens: the denominator of the size factor (default ' . LoadedCapability::DEFAULT_REFERENCE_RESIDENT_TOKENS . ')')
            ->addOption('evict-tool-tokens', null, InputOption::VALUE_REQUIRED, 'unloadToolAfterUnusedTokens: tokens after which an unused tool unloads (default ' . LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS . ')')
            ->addOption('evict-tool-turns', null, InputOption::VALUE_REQUIRED, 'unloadToolAfterUnusedTurns: turns after which an unused tool unloads (default ' . LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS . ')')
            ->addOption('evict-skill-tokens', null, InputOption::VALUE_REQUIRED, 'unloadSkillAfterUnusedTokens: tokens after which an unused skill unloads (default ' . LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS . ')')
            ->addOption('evict-skill-turns', null, InputOption::VALUE_REQUIRED, 'unloadSkillAfterUnusedTurns: turns after which an unused skill unloads (default ' . LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS . ')')
            ->addOption('evict-min-residency', null, InputOption::VALUE_REQUIRED, 'minResidencyTurns: no unload within this many turns of the load (default ' . LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS . ')')
            ->addOption(self::OPTION_UNLOAD_GRANULARITY, null, InputOption::VALUE_REQUIRED, 'unloadGranularity: `' . ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL . '` (a skill leaves with all its tools, once per owner turn) or `' . ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL . '` (the score of each tool after every step)')
            ->addOption(self::OPTION_SKILL_LOADING_STRATEGY, null, InputOption::VALUE_REQUIRED, 'skillLoadingStrategy: `' . implode('` or `', ContextRetentionSettings::SKILL_LOADING_STRATEGIES) . '`; takes effect on a new conversation only')
            ->addOption(self::OPTION_REBUILD_EPOCH_ON_SITUATION_CHANGE, null, InputOption::VALUE_REQUIRED, 'rebuildEpochOnSituationChange: `' . ContextRetentionSettingsOverride::SWITCH_ON . '` or `' . ContextRetentionSettingsOverride::SWITCH_OFF . '`; takes effect before the conversation\'s first model call only')
            ->addOption(self::OPTION_STATE_CHANGE_DELIVERY, null, InputOption::VALUE_REQUIRED, 'stateChangeDelivery: `' . implode('` or `', ContextRetentionSettings::STATE_CHANGE_DELIVERIES) . '`; takes effect before the conversation\'s first model call only');
    }

    /**
     * The context-retention knobs passed, parsed; null when none was passed. A blank value counts as not passed.
     *
     * @param InputInterface $input
     * @return ContextRetentionSettingsOverride|null
     * @throws InvalidOptionException naming the option whose value its knob does not accept
     */
    protected function buildContextRetentionSettingsOverrideFromInput(InputInterface $input): ?ContextRetentionSettingsOverride
    {
        $rawCountsByField = [];
        foreach (self::CONTEXT_RETENTION_COUNT_OPTION_NAMES_BY_FIELD as $fieldName => $optionName) {
            $rawCountsByField[$fieldName] = $this->readOptionValue($input, $optionName);
        }
        try {
            return ContextRetentionSettingsOverride::fromRawValues(
                $input->hasOption(self::OPTION_LOAD_TOOLS_ON_SKILL_LOAD) && (bool)$input->getOption(self::OPTION_LOAD_TOOLS_ON_SKILL_LOAD),
                $this->readOptionValue($input, self::OPTION_UNLOAD_GRANULARITY),
                $this->readOptionValue($input, self::OPTION_SKILL_LOADING_STRATEGY),
                $this->readOptionValue($input, self::OPTION_REBUILD_EPOCH_ON_SITUATION_CHANGE),
                $this->readOptionValue($input, self::OPTION_STATE_CHANGE_DELIVERY),
                $rawCountsByField
            );
        } catch (SettingsOverrideValueRefusedException $settingsOverrideValueRefusedException) {
            $optionNamesByField = self::CONTEXT_RETENTION_COUNT_OPTION_NAMES_BY_FIELD + self::CONTEXT_RETENTION_CHOICE_OPTION_NAMES_BY_FIELD;
            throw $this->buildOptionRefusal($settingsOverrideValueRefusedException, $optionNamesByField);
        }
    }

    /**
     * Declares the compaction options, each named `$optionNamePrefix` + its name in
     * {@see COMPACTION_OPTION_NAMES_BY_FIELD}: with the default prefix `--compact-trigger-tokens` and its siblings, with
     * an empty prefix (a command about compaction) `--trigger-tokens` and its siblings. Each option takes a value; a
     * command that wants a value even when the option is not passed names it in `$defaultValuesByField` (by field name
     * of {@see COMPACTION_OPTION_NAMES_BY_FIELD}). Call it from `configure()`.
     *
     * @param string $optionNamePrefix
     * @param string[] $defaultValuesByField the default value of an option, by field name; an option without one has none
     * @return void
     */
    protected function addCompactionSettingsOptions(string $optionNamePrefix = self::COMPACTION_OPTION_PREFIX, array $defaultValuesByField = []): void
    {
        $descriptionsByField = [
            'triggerTokens' => 'CompactionSettings.triggerTokens: the compaction threshold in tokens; set it low to force compaction on a short conversation',
            'keepRecentMessages' => 'CompactionSettings.keepRecentMessages: the newest messages kept verbatim (global default ' . CompactionSettings::DEFAULT_KEEP_RECENT_MESSAGES . ')',
            'segmentMessages' => 'CompactionSettings.segmentMessages: messages per leaf segment (global default ' . CompactionSettings::DEFAULT_SEGMENT_MESSAGES . ')',
            'tierFanIn' => 'CompactionSettings.tierFanIn: nodes of one level merged into one node of the next (global default ' . CompactionSettings::DEFAULT_TIER_FAN_IN . ', at least 2)',
            'levelRetention' => 'CompactionSettings.levelRetention: the newest nodes per level kept fine near the frontier (global default ' . CompactionSettings::DEFAULT_LEVEL_RETENTION . ')',
        ];
        foreach (self::COMPACTION_OPTION_NAMES_BY_FIELD as $fieldName => $optionName) {
            $this->addOption(
                $optionNamePrefix . $optionName,
                null,
                InputOption::VALUE_REQUIRED,
                $descriptionsByField[$fieldName],
                isset($defaultValuesByField[$fieldName]) ? (string)$defaultValuesByField[$fieldName] : null
            );
        }
    }

    /**
     * The compaction knobs passed (or given a default by the command), parsed; null when none has a value. A blank
     * value counts as not passed.
     *
     * @param InputInterface $input
     * @param string $optionNamePrefix the prefix the options were declared with ({@see addCompactionSettingsOptions()})
     * @return CompactionSettingsOverride|null
     * @throws InvalidOptionException naming the option whose value is no whole number or below its minimum
     */
    protected function buildCompactionSettingsOverrideFromInput(InputInterface $input, string $optionNamePrefix = self::COMPACTION_OPTION_PREFIX): ?CompactionSettingsOverride
    {
        $optionNames = self::COMPACTION_OPTION_NAMES_BY_FIELD;
        try {
            return CompactionSettingsOverride::fromRawValues(
                $this->readOptionValue($input, $optionNamePrefix . $optionNames['triggerTokens']),
                $this->readOptionValue($input, $optionNamePrefix . $optionNames['keepRecentMessages']),
                $this->readOptionValue($input, $optionNamePrefix . $optionNames['segmentMessages']),
                $this->readOptionValue($input, $optionNamePrefix . $optionNames['tierFanIn']),
                $this->readOptionValue($input, $optionNamePrefix . $optionNames['levelRetention'])
            );
        } catch (SettingsOverrideValueRefusedException $settingsOverrideValueRefusedException) {
            $prefixedOptionNamesByField = [];
            foreach ($optionNames as $fieldName => $optionName) {
                $prefixedOptionNamesByField[$fieldName] = $optionNamePrefix . $optionName;
            }
            throw $this->buildOptionRefusal($settingsOverrideValueRefusedException, $prefixedOptionNamesByField);
        }
    }

    /**
     * The console refusal of a refused settings value, naming the option the value came from.
     *
     * @param SettingsOverrideValueRefusedException $settingsOverrideValueRefusedException
     * @param string[] $optionNamesByField option name by field name
     * @return InvalidOptionException
     */
    protected function buildOptionRefusal(SettingsOverrideValueRefusedException $settingsOverrideValueRefusedException, array $optionNamesByField): InvalidOptionException
    {
        $optionName = $optionNamesByField[$settingsOverrideValueRefusedException->refusedFieldName] ?? $settingsOverrideValueRefusedException->refusedFieldName;
        return new InvalidOptionException(
            '--' . $optionName . ' ' . $settingsOverrideValueRefusedException->refusalReason,
            0,
            $settingsOverrideValueRefusedException
        );
    }

    /**
     * The raw value of a value option; null when the command does not declare it or it was not passed.
     *
     * @param InputInterface $input
     * @param string $optionName
     * @return string|null
     */
    protected function readOptionValue(InputInterface $input, string $optionName): ?string
    {
        if (!$input->hasOption($optionName)) {
            return null;
        }
        $optionValue = $input->getOption($optionName);
        return is_scalar($optionValue) ? (string)$optionValue : null;
    }
}
