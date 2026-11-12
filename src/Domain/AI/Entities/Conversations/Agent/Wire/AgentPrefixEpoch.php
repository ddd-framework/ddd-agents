<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * One PREFIX EPOCH of an append-only conversation: from the conversation start, or from the first row
 * after the compaction cover's edge, the system content up to and including the loaded skills and the top-level `tools`
 * stay fixed; every skill loaded later reaches the model as an item appended to the history. The epoch's base set is the
 * loaded capability set as it stood at the epoch start (the kickoff / child seed set at the conversation start; after a
 * compaction, the set right before the first raw row), read from the stored rows once per run.
 *
 * Under `rebuildEpochOnSituationChange` (opt-in) a change of the situation starts a SITUATION
 * EPOCH inside the cover epoch: from {@see $situationChangeStartIndex} on, the system content carries every skill loaded
 * at that point WITH the sections of the new situation ({@see $situationChangeBaseCapabilities}); the skill texts the
 * earlier rows of the cover epoch appended are no longer rendered (their tools items stay, so the top-level `tools` and
 * every tool already sent keep their bytes). The call that starts it records the index on its request snapshot
 * (`epochSituationChangeStartIndex`); every later call and the reconstruction read it back from the newest model call
 * row ({@see findRecordedSituationChangeStartIndex()}). One full cache miss after the base prompt per change.
 *
 * Pure: the base set, the cover fingerprint and the start index are gathered by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildPrefixEpoch()}.
 */
class AgentPrefixEpoch
{
    /** The hash algorithm of {@see buildCoverFingerprint()}: fast, 16 hex characters. */
    public const string FINGERPRINT_HASH_ALGORITHM = 'xxh64';

    /** @var int The first message index of the epoch: 0, or the cover edge + 1. */
    public int $startIndex = 0;

    /** @var string|null The fingerprint of the compaction cover the epoch was built on; null without a cover. */
    public ?string $coverFingerprint = null;

    /** @var LoadedCapabilities|null The loaded capability set at the epoch start; null = nothing loaded then. */
    public ?LoadedCapabilities $baseCapabilities = null;

    /** @var AISkills|null The resolved skills of {@see $baseCapabilities} (the context build resolves them). */
    public ?AISkills $baseAISkills = null;

    /**
     * @var AIConversationVariants|null The situation the epoch's system content renders with: the variants of the
     *      epoch's first model call (from its request snapshot), so a variant flip inside the epoch leaves the system
     *      content byte-stable — the flip reaches the model as an appended item instead. Null = the call's own variants.
     */
    public ?AIConversationVariants $variantsAtEpochStart = null;

    /**
     * @var AgentAppendOnlyToolSet|null Which tools the epoch carries top-level: the purpose's whole
     *      reachable domain tool set, or the base set's tools; null = the base set's tools (a context built without it).
     */
    public ?AgentAppendOnlyToolSet $appendOnlyToolSet = null;

    /**
     * Whether the system content carries the loaded skills and the purpose directive WITH the sections of the epoch's
     * situation and a situation change starts a new situation epoch (the conversation's `rebuildEpochOnSituationChange`);
     * false = the round-4 behaviour: the sections ride appended `# Situation` entries.
     */
    public bool $rebuildsOnSituationChange = false;

    /**
     * @var int|null The first message index of the situation epoch: the newest history row the call that started it saw
     *      + 1; null = no situation change inside the cover epoch so far (the system content renders the cover epoch's
     *      base set).
     */
    public ?int $situationChangeStartIndex = null;

    /** @var LoadedCapabilities|null The loaded capability set right before {@see $situationChangeStartIndex}. */
    public ?LoadedCapabilities $situationChangeBaseCapabilities = null;

    /** @var AISkills|null The resolved skills of {@see $situationChangeBaseCapabilities}. */
    public ?AISkills $situationChangeBaseAISkills = null;

    /**
     * @var string[] The conditional meta tools ({@see AgentAppendOnlyCall::CONDITIONAL_META_TOOL_NAMES})
     *      the rows between the cover epoch's start and a later state epoch start appended — rows of the history still
     *      call them, so the epoch's first call appends them again, also when their condition has ended. [] otherwise.
     */
    public array $carriedConditionalMetaToolNames = [];

    /**
     * The conditional meta tools the model calls in `[$fromIndex, $beforeIndex)` appended (their request snapshots'
     * `appendedToolNames`), in history order.
     *
     * @param AIConversationMessage[] $contextMessages
     * @return string[]
     */
    public static function findConditionalMetaToolNamesAppendedBetween(array $contextMessages, int $fromIndex, int $beforeIndex): array
    {
        $conditionalMetaToolNames = [];
        foreach ($contextMessages as $contextMessage) {
            $messageIndex = $contextMessage->indexInConversation ?? -1;
            if ($messageIndex < $fromIndex || $messageIndex >= $beforeIndex || ($contextMessage->inputTokens ?? 0) <= 0 || !isset($contextMessage->requestSnapshot)) {
                continue;
            }
            foreach ($contextMessage->requestSnapshot->appendedToolNames ?? [] as $appendedToolName) {
                if (in_array($appendedToolName, AgentAppendOnlyCall::CONDITIONAL_META_TOOL_NAMES, true) && !in_array($appendedToolName, $conditionalMetaToolNames, true)) {
                    $conditionalMetaToolNames[] = (string)$appendedToolName;
                }
            }
        }
        return $conditionalMetaToolNames;
    }

    /** Whether the epoch carries the purpose's whole reachable domain tool set top-level. */
    public function offersReachableToolsTopLevel(): bool
    {
        return $this->appendOnlyToolSet?->offersReachableToolsTopLevel() ?? false;
    }

    /**
     * The fingerprint of a compaction cover: a hash of the sorted `(level,
     * coveredFromMessageIndex, coveredToMessageIndex)` of the cover nodes — they identify a node uniquely and exist on the
     * id-less nodes of an ephemeral conversation too. Null for an empty cover.
     *
     * @param AIConversationCompactionNode[] $coverNodes
     */
    public static function buildCoverFingerprint(array $coverNodes): ?string
    {
        $nodeKeys = [];
        foreach ($coverNodes as $coverNode) {
            $nodeKeys[] = ($coverNode->level ?? 0) . ':' . ($coverNode->coveredFromMessageIndex ?? 0) . '-' . ($coverNode->coveredToMessageIndex ?? 0);
        }
        if ($nodeKeys === []) {
            return null;
        }
        sort($nodeKeys);
        return hash(self::FINGERPRINT_HASH_ALGORITHM, implode('|', $nodeKeys));
    }

    /**
     * The situation-change start index the newest model call of the cover epoch recorded (its request snapshot's
     * `epochSituationChangeStartIndex`), or null when that call recorded none or one below the cover epoch's start (a
     * compaction since then starts the epoch anew). The newest call states the epoch it ran in, so a later call and a
     * point-in-time rebuild (whose context ends at the rebuilt call) find the same epoch.
     *
     * @param AIConversationMessage[] $contextMessages
     */
    public static function findRecordedSituationChangeStartIndex(array $contextMessages, int $coverEpochStartIndex): ?int
    {
        for ($messagePosition = count($contextMessages) - 1; $messagePosition >= 0; $messagePosition--) {
            $contextMessage = $contextMessages[$messagePosition];
            if (($contextMessage->indexInConversation ?? -1) < $coverEpochStartIndex) {
                return null;
            }
            if (($contextMessage->inputTokens ?? 0) <= 0 || !isset($contextMessage->requestSnapshot)) {
                continue;
            }
            $recordedStartIndex = $contextMessage->requestSnapshot->epochSituationChangeStartIndex;
            return $recordedStartIndex !== null && $recordedStartIndex >= $coverEpochStartIndex ? $recordedStartIndex : null;
        }
        return null;
    }

    /**
     * Whether a model call with `$callVariants` starts a new situation epoch: only under {@see $rebuildsOnSituationChange},
     * when the epoch already had a call (its situation is known), the call's situation differs from the epoch's and a
     * loaded skill or the purpose directive is cut into situation sections (`$cutNames`, the condition of a replacing
     * `# Situation` entry under the round-4 behaviour).
     *
     * @param string[] $cutNames
     */
    public function isStartedAnewBySituationOf(?AIConversationVariants $callVariants, array $cutNames): bool
    {
        return $this->rebuildsOnSituationChange
            && $this->variantsAtEpochStart !== null
            && $cutNames !== []
            && $callVariants?->toSelectorMap() !== $this->variantsAtEpochStart->toSelectorMap();
    }

    /** Start the situation epoch at `$situationChangeStartIndex` with its base set and situation. */
    public function startSituationEpoch(
        int $situationChangeStartIndex,
        ?LoadedCapabilities $situationChangeBaseCapabilities,
        ?AISkills $situationChangeBaseAISkills,
        ?AIConversationVariants $situationVariants
    ): void {
        $this->situationChangeStartIndex = $situationChangeStartIndex;
        $this->situationChangeBaseCapabilities = $situationChangeBaseCapabilities;
        $this->situationChangeBaseAISkills = $situationChangeBaseAISkills;
        $this->variantsAtEpochStart = $situationVariants;
    }

    /** The first index whose recorded skill texts are rendered: the situation epoch's start, else the epoch's start. */
    public function getSkillTextStartIndex(): int
    {
        return max($this->startIndex, $this->situationChangeStartIndex ?? $this->startIndex);
    }

    /** The capability set the system content renders: the situation epoch's base set, else the epoch's base set. */
    public function getSystemContentCapabilities(): ?LoadedCapabilities
    {
        return $this->situationChangeStartIndex !== null ? $this->situationChangeBaseCapabilities : $this->baseCapabilities;
    }

    /** The resolved skills of {@see getSystemContentCapabilities()}. */
    public function getSystemContentAISkills(): ?AISkills
    {
        return $this->situationChangeStartIndex !== null ? $this->situationChangeBaseAISkills : $this->baseAISkills;
    }

    /** @return string[] the skill names the system content renders */
    public function getSystemContentSkillNames(): array
    {
        return $this->getSystemContentCapabilities()?->getLoadedSkillNames() ?? [];
    }

    /** @return string[] the skill names of the base set */
    public function getBaseSkillNames(): array
    {
        return $this->baseCapabilities?->getLoadedSkillNames() ?? [];
    }

    /** @return string[] the tool names of the base set */
    public function getBaseToolNames(): array
    {
        return $this->baseCapabilities?->getLoadedToolNames() ?? [];
    }
}
