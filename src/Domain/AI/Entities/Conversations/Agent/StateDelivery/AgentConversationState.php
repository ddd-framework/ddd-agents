<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

/**
 * The volatile values of a conversation's model input as the model knows them: as the EPOCH RECORD
 * (the values at the state epoch's start — stored on the epoch's first model call, {@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::$stateAtEpochStart};
 * the system content and the base-prompt placeholders render from it for the whole epoch), as the LAST-SEEN values
 * (the record with every recorded delta applied, {@see withDeltaApplied()}) and as the CURRENT values of a call (what a
 * per-call render would show now). The values only the volatile tail carried (the unconfirmed-zone note, the situation
 * provenance note, the task plan) are null in a record: nothing of them is in the system content, so the epoch's first
 * call delivers them. Each delta kind ({@see AgentStateDelta}) reads and sets one value ({@see readValueOfKind()}). Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentConversationState
{
    /** @var string|null The `{%AGENT_SPACE_CONTEXT%}` text; null = the conversation has none (no space). */
    public ?string $agentSpaceContext = null;

    /** @var string|null The `{%OWNER_ADDRESSING%}` text; null = none resolved. */
    public ?string $ownerAddressing = null;

    /** @var string|null The IANA zone of the `# Time` rule and the brackets; null = a conversation without a zone. */
    public ?string $timezoneIdentifier = null;

    /** @var string|null The `[SIGNAL:time-zone]` note of an unconfirmed zone the model saw; null = none. */
    public ?string $timezoneUnconfirmedNote = null;

    /** @var string|null The standing-instructions block (the MEMORY_DIRECTIVES segment text). */
    public ?string $memoryDirectives = null;

    /** @var string|null The plan & capabilities block (the PRODUCT_CAPABILITIES segment body). */
    public ?string $productCapabilities = null;

    /** @var string|null The AI-budget block (the AI_BUDGET_AWARENESS segment body). */
    public ?string $aiBudgetAwareness = null;

    /** @var string|null The restricted-capabilities block (the RESTRICTED_CAPABILITIES segment body). */
    public ?string $restrictedCapabilities = null;

    /** @var string|null The `[SIGNAL:situation]` provenance note the model saw; null = none. */
    public ?string $situationNote = null;

    /**
     * @var string|null Who reads the call on which surface (the owner presence, the phone line, the kit): a change
     *      starts a new state epoch, the base prompt's `[surface=…]` sections render per surface.
     */
    public ?string $surfaceKey = null;

    /**
     * @var string[] The kinds whose value is not known: in the CURRENT values of a call (e.g. the base-prompt values of a
     *      build outside a run, a failed resolution) they are compared with nothing and never delivered; in an EPOCH
     *      RECORD they are the base-prompt values its first call could not resolve — the system content omitted them
     *      (never a literal placeholder), and the first resolved value reaches the model as a note. A delivered delta of
     *      the kind removes it ({@see writeValueOfKind()}).
     */
    public array $unknownKinds = [];

    /**
     * The snapshot key the context text of the space was stored under before the module named it
     * `agentSpaceContext`: an epoch record stored under it is still read ({@see fromArray()}).
     */
    public const string FORMER_SNAPSHOT_KEY_OF_AGENT_SPACE_CONTEXT = 'businessContext';

    /** @return string[] The kinds an epoch record keeps as unknown: the two base-prompt values a run resolves. */
    public static function getKindsAnEpochRecordKeepsUnknown(): array
    {
        return [AgentStateDelta::getKindOfAgentSpaceContext(), AgentStateDelta::KIND_OWNER_ADDRESSING];
    }

    /** Whether the value of `$kind` is unknown here. */
    public function isKindUnknown(string $kind): bool
    {
        return in_array($kind, $this->unknownKinds, true);
    }

    /** The value the kind compares and sets. */
    public function readValueOfKind(string $kind): mixed
    {
        return match ($kind) {
            AgentStateDelta::getKindOfAgentSpaceContext() => $this->agentSpaceContext,
            AgentStateDelta::KIND_OWNER_ADDRESSING => $this->ownerAddressing,
            AgentStateDelta::KIND_TIME_ZONE => ['zone' => $this->timezoneIdentifier, 'unconfirmedNote' => $this->timezoneUnconfirmedNote],
            AgentStateDelta::KIND_STANDING_INSTRUCTIONS => $this->memoryDirectives,
            AgentStateDelta::KIND_PLAN_OF_THE_PRODUCT => [
                'productCapabilities' => $this->productCapabilities,
                'aiBudgetAwareness' => $this->aiBudgetAwareness,
                'restrictedCapabilities' => $this->restrictedCapabilities,
            ],
            AgentStateDelta::KIND_SITUATION => $this->situationNote,
            default => null,
        };
    }

    /** Set the value of `$kind` (the {@see AgentStateDelta::$valueAfter} of a delivered delta). */
    public function writeValueOfKind(string $kind, mixed $value): void
    {
        $this->unknownKinds = array_values(array_diff($this->unknownKinds, [$kind]));
        switch ($kind) {
            case AgentStateDelta::getKindOfAgentSpaceContext():
                $this->agentSpaceContext = is_string($value) ? $value : null;
                break;
            case AgentStateDelta::KIND_OWNER_ADDRESSING:
                $this->ownerAddressing = is_string($value) ? $value : null;
                break;
            case AgentStateDelta::KIND_TIME_ZONE:
                $this->timezoneIdentifier = is_array($value) && is_string($value['zone'] ?? null) ? $value['zone'] : null;
                $this->timezoneUnconfirmedNote = is_array($value) && is_string($value['unconfirmedNote'] ?? null) ? $value['unconfirmedNote'] : null;
                break;
            case AgentStateDelta::KIND_STANDING_INSTRUCTIONS:
                $this->memoryDirectives = is_string($value) ? $value : null;
                break;
            case AgentStateDelta::KIND_PLAN_OF_THE_PRODUCT:
                $this->productCapabilities = is_array($value) && is_string($value['productCapabilities'] ?? null) ? $value['productCapabilities'] : null;
                $this->aiBudgetAwareness = is_array($value) && is_string($value['aiBudgetAwareness'] ?? null) ? $value['aiBudgetAwareness'] : null;
                $this->restrictedCapabilities = is_array($value) && is_string($value['restrictedCapabilities'] ?? null) ? $value['restrictedCapabilities'] : null;
                break;
            case AgentStateDelta::KIND_SITUATION:
                $this->situationNote = is_string($value) ? $value : null;
                break;
        }
    }

    /** A copy with `$agentStateDelta` delivered. */
    public function withDeltaApplied(AgentStateDelta $agentStateDelta): self
    {
        $stateAfterTheDelta = clone $this;
        $stateAfterTheDelta->writeValueOfKind($agentStateDelta->kind, $agentStateDelta->valueAfter);
        return $stateAfterTheDelta;
    }

    /**
     * The epoch record of these values: the system-content values kept, the tail-only values (the unconfirmed-zone note,
     * the situation note, the task plan) emptied — the epoch's first call delivers them as deltas — and the base-prompt
     * values that could not be resolved kept as unknown (never a known `null`).
     */
    public function buildEpochRecord(): self
    {
        $epochRecord = clone $this;
        $epochRecord->timezoneUnconfirmedNote = null;
        $epochRecord->situationNote = null;
        $epochRecord->unknownKinds = array_values(array_intersect($this->unknownKinds, self::getKindsAnEpochRecordKeepsUnknown()));
        return $epochRecord;
    }

    /** @return array<string, mixed> the snapshot form */
    public function toArray(): array
    {
        return [
            'agentSpaceContext' => $this->agentSpaceContext,
            'ownerAddressing' => $this->ownerAddressing,
            'timezoneIdentifier' => $this->timezoneIdentifier,
            'timezoneUnconfirmedNote' => $this->timezoneUnconfirmedNote,
            'memoryDirectives' => $this->memoryDirectives,
            'productCapabilities' => $this->productCapabilities,
            'aiBudgetAwareness' => $this->aiBudgetAwareness,
            'restrictedCapabilities' => $this->restrictedCapabilities,
            'situationNote' => $this->situationNote,
            'surfaceKey' => $this->surfaceKey,
            'unknownKinds' => $this->unknownKinds,
        ];
    }

    /**
     * @param array<string, mixed> $stateArray the snapshot form; the context text of the space is also read from the key
     *                                         it was stored under before ({@see FORMER_SNAPSHOT_KEY_OF_AGENT_SPACE_CONTEXT})
     */
    public static function fromArray(array $stateArray): static
    {
        $agentConversationState = new static();
        foreach (['agentSpaceContext', 'ownerAddressing', 'timezoneIdentifier', 'timezoneUnconfirmedNote', 'memoryDirectives', 'productCapabilities', 'aiBudgetAwareness', 'restrictedCapabilities', 'situationNote', 'surfaceKey'] as $stringField) {
            $agentConversationState->{$stringField} = is_string($stateArray[$stringField] ?? null) ? $stateArray[$stringField] : null;
        }
        $contextTextUnderTheFormerKey = $stateArray[self::FORMER_SNAPSHOT_KEY_OF_AGENT_SPACE_CONTEXT] ?? null;
        if ($agentConversationState->agentSpaceContext === null && is_string($contextTextUnderTheFormerKey)) {
            $agentConversationState->agentSpaceContext = $contextTextUnderTheFormerKey;
        }
        $agentConversationState->unknownKinds = array_values(array_filter(
            is_array($stateArray['unknownKinds'] ?? null) ? $stateArray['unknownKinds'] : [],
            static fn (mixed $unknownKind): bool => is_string($unknownKind)
        ));
        return $agentConversationState;
    }
}
