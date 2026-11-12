<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;


/**
 * The deltas one model call owes: every kind whose current value differs from what the model saw
 * (the epoch record with the recorded deltas applied), in {@see AgentStateDelta::getKindsInDeliveryOrder()}. Compared on
 * EVERY call — a delta found by a call whose response was discarded is found again by the next call.
 * A kind whose current value is unknown ({@see AgentConversationState::$unknownKinds}) is skipped. A note about the
 * space's context text names every field changed since the EPOCH RECORD (the context line of the instructions the model
 * reads), not only since the previous note: the newest note of the kind carries the whole current delta and replaces the
 * earlier ones. Pure.
 */
class AgentStateChangeDetection
{
    /** @return AgentStateDelta[] */
    public static function buildDeltasFor(
        AgentConversationState $seenState,
        AgentConversationState $currentState,
        ?AgentConversationState $epochRecord = null
    ): array {
        $agentStateDeltas = [];
        foreach (AgentStateDelta::getKindsInDeliveryOrder() as $kind) {
            if (in_array($kind, $currentState->unknownKinds, true)) {
                continue;
            }
            $agentStateDelta = self::buildDeltaOfKind($kind, $seenState, $currentState, $epochRecord);
            if ($agentStateDelta !== null) {
                $agentStateDeltas[] = $agentStateDelta;
            }
        }
        return $agentStateDeltas;
    }

    /**
     * The delta of one value kind; null when the value is unchanged. `$epochRecord` (null = the
     * seen state) is what the change lines of a note about the space's context text are named against.
     */
    public static function buildDeltaOfKind(string $kind, AgentConversationState $seenState, AgentConversationState $currentState, ?AgentConversationState $epochRecord = null): ?AgentStateDelta
    {
        $seenValue = $seenState->readValueOfKind($kind);
        $currentValue = $currentState->readValueOfKind($kind);
        if ($seenValue === $currentValue) {
            return null;
        }
        // the epoch's first call could not resolve the value and omitted it: a value that is still empty (no context
        // text of the space, no name to address the owner by) tells the model nothing new
        if ($seenState->isKindUnknown($kind) && ($currentValue === null || $currentValue === '')) {
            return null;
        }
        $deltaText = match ($kind) {
            AgentStateDelta::getKindOfAgentSpaceContext() => AgentStateDeltaTexts::buildAgentSpaceContextText(($epochRecord ?? $seenState)->agentSpaceContext, $currentState->agentSpaceContext),
            AgentStateDelta::KIND_OWNER_ADDRESSING => AgentStateDeltaTexts::buildOwnerAddressingText($currentState->ownerAddressing),
            AgentStateDelta::KIND_TIME_ZONE => AgentStateDeltaTexts::buildTimeZoneText(
                $seenState->timezoneIdentifier,
                $currentState->timezoneIdentifier,
                $seenState->timezoneUnconfirmedNote,
                $currentState->timezoneUnconfirmedNote
            ),
            AgentStateDelta::KIND_STANDING_INSTRUCTIONS => AgentStateDeltaTexts::buildStandingInstructionsText($currentState->memoryDirectives),
            AgentStateDelta::KIND_PLAN_OF_THE_PRODUCT => AgentStateDeltaTexts::buildPlanOfTheProductText(
                is_array($seenValue) ? $seenValue : [],
                is_array($currentValue) ? $currentValue : []
            ),
            AgentStateDelta::KIND_SITUATION => AgentStateDeltaTexts::buildSituationText($currentState->situationNote),
            default => '',
        };
        // a zone that went away (a build without a space) has nothing to say: nothing is delivered
        return $deltaText !== '' ? AgentStateDelta::of($kind, $deltaText, $currentValue) : null;
    }
}
