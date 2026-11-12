<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The supervisor's committed settings (`config/app/AI/supervisor.php`, read as `AI.supervisor`) as one typed value:
 * the global switch, the default checkpoint model, the reasoning effort per beat and the policy of a supervised
 * conversation without a purpose. Built per read from the config array — never
 * cached on a shared service. The committed config is the only source: no environment variable switches supervision
 * (the eval arm is `--supervisor`).
 *
 * @phpstan-consistent-constructor
 */
class SupervisorSettings extends ValueObject
{
    /** The effort of a SETTLED beat when the config names none. */
    public const string DEFAULT_REASONING_EFFORT = 'medium';

    /** The effort of a STEP beat when the config names none (speed over depth). */
    public const string DEFAULT_STEP_REASONING_EFFORT = 'low';

    /** The words that switch supervision on (case-insensitive, trimmed); anything else is off. */
    public const array SWITCH_ON_WORDS = ['1', 'true', 'yes', 'on'];

    /** The GLOBAL switch: false = only a conversation with a run-cap override above zero is supervised. */
    public bool $enabled = false;

    /** The configured default checkpoint model (trimmed); null = the ORCHESTRATOR scope default of the catalog. */
    public ?string $modelName = null;

    /** The effort of a SETTLED beat, as configured (cast to string; the default only when the key is missing or null). */
    public string $reasoningEffort = self::DEFAULT_REASONING_EFFORT;

    /** The effort of a STEP beat, as configured (cast to string; the default only when the key is missing or null). */
    public string $stepReasoningEffort = self::DEFAULT_STEP_REASONING_EFFORT;

    /**
     * The {@see SupervisorPolicy} class a supervised conversation WITHOUT a purpose (a free dialog an admin armed) is
     * briefed and judged with (trimmed; null = the AI base policy, which knows no purpose).
     */
    public ?string $policyClassForConversationsWithoutAPurpose = null;

    /**
     * The settings from the committed config array: the switch is the config's `enabled`, otherwise OFF.
     *
     * @param array<string, mixed> $configured
     */
    public static function fromConfig(array $configured): static
    {
        $supervisorSettings = new static();
        $supervisorSettings->enabled = self::resolveEnabled($configured);
        $configuredModelName = trim((string)($configured['modelName'] ?? ''));
        $supervisorSettings->modelName = $configuredModelName !== '' ? $configuredModelName : null;
        $supervisorSettings->reasoningEffort = (string)($configured['reasoningEffort'] ?? self::DEFAULT_REASONING_EFFORT);
        $supervisorSettings->stepReasoningEffort = (string)($configured['stepReasoningEffort'] ?? self::DEFAULT_STEP_REASONING_EFFORT);
        $configuredPolicyClass = trim((string)($configured['policyClassForConversationsWithoutAPurpose'] ?? ''));
        $supervisorSettings->policyClassForConversationsWithoutAPurpose = $configuredPolicyClass !== '' ? $configuredPolicyClass : null;
        return $supervisorSettings;
    }

    /**
     * The switch rule: the config's `enabled` (bool or a switch word), otherwise OFF. Never throws: a mistyped flag turns
     * supervision off, never on.
     *
     * @param array<string, mixed> $configured
     */
    public static function resolveEnabled(array $configured): bool
    {
        return array_key_exists('enabled', $configured) && self::isSwitchValueOn($configured['enabled']);
    }

    /** Whether one switch value means ON: a bool as it is, else one of {@see SWITCH_ON_WORDS}. */
    public static function isSwitchValueOn(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)($value ?? ''))), self::SWITCH_ON_WORDS, true);
    }

    /** The reasoning effort a checkpoint of this beat runs at: the step effort on a step beat, else the settled one. */
    public function getEffortForBeat(string $beat): string
    {
        return $beat === SupervisorCheckpointBriefingHeader::BEAT_STEP ? $this->stepReasoningEffort : $this->reasoningEffort;
    }
}
