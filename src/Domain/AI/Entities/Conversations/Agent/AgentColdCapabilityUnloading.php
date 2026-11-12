<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityRecencies;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillGroups;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The per-turn unloading of cold resident capabilities — pure over
 * the conversation: it changes the anchor message's {@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities}
 * in memory and says what left and why. The caller persists the anchor's capability column and records one `eviction`
 * event per victim, in that order (a crash in between must not leave a phantom event row).
 *
 * Two granularities: SKILL (`unloadsPerSkill`) — a skill whose group saw no use for the configured owner turns leaves
 * whole, a tool with a surviving parent stays; TOOL — the cost-benefit sweep over single capabilities, where an unused
 * speculative preload always goes first (no collateral while one is resident) and the sweep is re-run.
 *
 * @phpstan-consistent-constructor
 */
class AgentColdCapabilityUnloading extends ValueObject
{
    /** The message whose capability set changed — its capability column is what the caller persists. */
    public ?AIConversationMessage $anchorMessage = null;

    public string $granularity = ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL;

    /** @var string[] the unused speculative preloads removed before the tool sweep re-ran (evicted by `speculative`) */
    public array $speculativeFirstNames = [];

    /** @var array<string, array<string, mixed>|null> victim name → its telemetry right before it left */
    public array $priorTelemetryByVictimName = [];

    /** Whether the anchor's set changed at all — nothing to persist otherwise. */
    public function changedTheSet(): bool
    {
        return $this->speculativeFirstNames !== [] || $this->priorTelemetryByVictimName !== [];
    }

    /** The reason the victims of this unloading carry (`sweep` or `skillUnloading`). */
    public function getEvictedBy(): string
    {
        return $this->granularity === ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL ? 'skillUnloading' : 'sweep';
    }

    /** Unload the cold capabilities of the conversation's newest capability-carrying message; null when nothing applies. */
    public static function applyTo(AIConversation $conversation): ?static
    {
        // anchor on the newest message that CARRIES a set (at a turn start `last()` is the initiator row).
        $anchorMessage = $conversation->messages->getNewestMessageWithLoadedCapabilities();
        if ($anchorMessage === null || !$anchorMessage->hasLoadedCapabilities()) {
            return null;
        }
        $settings = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings : new ContextRetentionSettings();
        if (!$settings->isEnabled()) {
            return null;
        }
        $unloading = new static();
        $unloading->anchorMessage = $anchorMessage;
        $currentTurnIndex = $conversation->buildOwnerTurns()->countOwnerTurns();
        if ($settings->unloadsPerSkill()) {
            $unloading->granularity = ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL;
            $unloading->unloadColdSkillGroups($conversation, $settings, $currentTurnIndex);
            return $unloading;
        }
        $unloading->sweepColdCapabilities($conversation, $settings, $currentTurnIndex);
        return $unloading;
    }

    protected function sweepColdCapabilities(AIConversation $conversation, ContextRetentionSettings $settings, int $currentTurnIndex): void
    {
        $loadedCapabilities = $this->anchorMessage->getLoadedCapabilities();
        $contextInputTokens = $conversation->getLatestContextInputTokens() ?? 0;
        $evictNames = $loadedCapabilities->computeEviction($contextInputTokens, $currentTurnIndex, $settings);
        $nextInitiatorIndex = ($conversation->messages->getNewestInitiatorMessageIndex() ?? 0) + 1;
        $speculativeUnusedNames = $loadedCapabilities->getSpeculativeUnusedNames($nextInitiatorIndex);
        if ($speculativeUnusedNames !== [] && array_diff($evictNames, $speculativeUnusedNames) !== []) {
            $loadedCapabilities->removeSpeculativeUnused($nextInitiatorIndex);
            $this->speculativeFirstNames = $speculativeUnusedNames;
            $evictNames = $loadedCapabilities->computeEviction($contextInputTokens, $currentTurnIndex, $settings);
        }
        if ($evictNames === []) {
            return;
        }
        foreach ($evictNames as $victimName) {
            $victim = $loadedCapabilities->getByName($victimName);
            $this->priorTelemetryByVictimName[$victimName] = $victim === null ? null : ['type' => $victim->type, 'source' => $victim->source, 'usageCountSinceLoad' => $victim->usageCountSinceLoad, 'turnIndexAtLastUse' => $victim->turnIndexAtLastUse, 'residentTokens' => $victim->residentTokens];
        }
        $loadedCapabilities->recordUnloadReason('sweep', ...$evictNames);
        $loadedCapabilities->removeByNames(...$evictNames);
    }

    /**
     * SKILL-granular unloading: "owner turns since" is derived from the compaction-proof initiator stamp
     * over the loaded tail; a stamp-less row (legacy, `tool`-mode history) falls back to the turn count.
     */
    protected function unloadColdSkillGroups(AIConversation $conversation, ContextRetentionSettings $settings, int $currentTurnIndex): void
    {
        $loadedCapabilities = $this->anchorMessage->getLoadedCapabilities();
        $currentInitiatorIndex = $conversation->messages->getNewestInitiatorMessageIndex();
        $leavingNames = $loadedCapabilities->computeSkillUnloading(
            $settings,
            LoadedSkillGroups::buildFor($loadedCapabilities, $this->anchorMessage->findLoadedAISkills()),
            LoadedCapabilityRecencies::buildFor($loadedCapabilities, $conversation->messages->buildOwnerTurns(), $currentInitiatorIndex, $currentTurnIndex)
        );
        if ($leavingNames === []) {
            return;
        }
        foreach ($leavingNames as $victimName) {
            $victim = $loadedCapabilities->getByName($victimName);
            $this->priorTelemetryByVictimName[$victimName] = $victim === null ? null : ['type' => $victim->type, 'source' => $victim->source, 'usageCountSinceLoad' => $victim->usageCountSinceLoad, 'turnIndexAtLastUse' => $victim->turnIndexAtLastUse, 'lastUsedInitiatorIndex' => $victim->lastUsedInitiatorIndex, 'residentTokens' => $victim->residentTokens];
        }
        $loadedCapabilities->detachLeavingParents($leavingNames);
        $loadedCapabilities->recordUnloadReason('skillUnloading', ...$leavingNames);
        $loadedCapabilities->removeByNames(...$leavingNames);
    }
}
