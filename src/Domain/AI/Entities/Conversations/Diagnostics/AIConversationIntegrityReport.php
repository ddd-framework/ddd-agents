<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The integrity checks of one persisted conversation — what every extraction step and synthetic run is
 * validated against. Pure over the projected rows and the decision events; a console command of the application
 * renders it and exits non-zero on a violation. Per turn (the boundaries are the initiator indexes the turn events
 * recorded):
 *  - every skill a preload decision admitted is on its ANCHOR — a row from the initiator up to the turn's first model
 *    output (the conversation's last row at decision time: the initiator or a signal appended after it);
 *  - the model never loads a skill again in the turn its preload admitted it;
 *  - no admission failed (`admissionError`: the write threw and rolled back — the turn ran without its preload);
 *  - with no run active, every tool call is final;
 *  - no tool-call id appears twice, no index is missing.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationIntegrityReport extends ValueObject
{
    public const string CHECK_PRELOAD_ON_ANCHOR = 'preloadOnAnchor';
    public const string CHECK_NO_SAME_TURN_RELOAD = 'noSameTurnReload';
    public const string CHECK_TOOL_CALLS_FINAL = 'toolCallsFinal';
    public const string CHECK_UNIQUE_TOOL_CALL_IDS = 'uniqueToolCallIds';
    public const string CHECK_CONTIGUOUS_INDEXES = 'contiguousIndexes';
    public const string CHECK_ADMISSION_SUCCEEDED = 'admissionSucceeded';


    /** @var AIConversationIntegrityViolation[] The failed checks, in the order they were found */
    public array $violations = [];

    /** @var AIConversationIntegrityAdmittedPreload[] One per admitted skill, in the order of the preload decisions */
    public array $admittedPreloads = [];

    public int $rowCount = 0;

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /** @return string[] The failed check of every violation, in the order they were found */
    public function getViolatedChecks(): array
    {
        return array_map(static fn (AIConversationIntegrityViolation $integrityViolation): string => $integrityViolation->check, $this->violations);
    }

    /** The number of admitted skills a row before the turn's first model output loads. */
    public function countAdmittedPreloadsOnTheirAnchor(): int
    {
        return count(array_filter($this->admittedPreloads, static fn (AIConversationIntegrityAdmittedPreload $admittedPreload): bool => $admittedPreload->onAnchor));
    }

    /** The number of admitted skills the model loaded again in the same turn. */
    public function countAdmittedPreloadsLoadedAgain(): int
    {
        return count(array_filter($this->admittedPreloads, static fn (AIConversationIntegrityAdmittedPreload $admittedPreload): bool => $admittedPreload->reloaded));
    }

    /**
     * @param array<int, array<string, mixed>> $messageRows {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::findIntegrityProjectionRows()}
     * @param AIConversationIntegrityRecords $integrityRecords the conversation's stored preload decisions and turn boundaries
     * @param string|null $runState the conversation's stored run state
     */
    public static function buildFromRecords(array $messageRows, AIConversationIntegrityRecords $integrityRecords, ?string $runState): static
    {
        $report = new static();
        $report->rowCount = count($messageRows);
        $liveRows = array_values(array_filter($messageRows, static fn (array $row): bool => !(bool)($row['truncated'] ?? false)));
        $turnInitiatorIndexes = $integrityRecords->turnInitiatorIndexes;
        sort($turnInitiatorIndexes);

        foreach ($integrityRecords->preloadDecisions as $preloadDecision) {
            if ($preloadDecision->skipReason === AgentSkillPreloadDecision::SKIP_ADMISSION_ERROR) {
                $report->addViolation(self::CHECK_ADMISSION_SUCCEEDED, "turn @{$preloadDecision->initiatorMessageIndex}: the admission of pass " . ($preloadDecision->pass ?? '?') . ' threw and rolled back');
            }
            if (!$preloadDecision->applied) {
                continue;
            }
            $initiatorIndex = $preloadDecision->initiatorMessageIndex;
            $turnEndIndex = self::nextInitiatorIndexAfter($turnInitiatorIndexes, $initiatorIndex);
            $firstModelOutputIndex = self::firstModelOutputIndexAfter($liveRows, $initiatorIndex, $turnEndIndex);
            foreach ($preloadDecision->admittedSkillNames as $skillName) {
                $onAnchor = false;
                $reloaded = false;
                foreach ($liveRows as $row) {
                    $rowIndex = (int)$row['indexInConversation'];
                    if ($rowIndex >= $initiatorIndex && $rowIndex < $firstModelOutputIndex && self::capabilityColumnLoads((string)($row['loadedCapabilities'] ?? ''), $skillName)) {
                        $onAnchor = true;
                    }
                    if ($rowIndex > $initiatorIndex && $rowIndex < $turnEndIndex && ($row['toolName'] ?? null) === 'load_skills'
                        && in_array($skillName, self::decodeNames($row['requestedSkillNames'] ?? null), true)
                    ) {
                        $reloaded = true;
                    }
                }
                $report->admittedPreloads[] = AIConversationIntegrityAdmittedPreload::forSkillOfTurn($initiatorIndex, $skillName, $onAnchor, $reloaded);
                if (!$onAnchor) {
                    $report->addViolation(self::CHECK_PRELOAD_ON_ANCHOR, "turn @{$initiatorIndex}: admitted {$skillName} is on no anchor row before the first model output (@{$firstModelOutputIndex})");
                }
                if ($reloaded) {
                    $report->addViolation(self::CHECK_NO_SAME_TURN_RELOAD, "turn @{$initiatorIndex}: the model loaded the admitted {$skillName} again");
                }
            }
        }

        $runIsActive = AIConversationRunState::of($runState)->isNonTerminal();
        $seenToolCallIds = [];
        foreach ($liveRows as $row) {
            $toolStatus = $row['toolStatus'] ?? null;
            if (!$runIsActive && ($row['toolName'] ?? null) !== null
                && in_array($toolStatus, [AIConversationMessage::STATUS_PENDING, AIConversationMessage::STATUS_RUNNING], true)
            ) {
                $report->addViolation(self::CHECK_TOOL_CALLS_FINAL, "@{$row['indexInConversation']}: {$row['toolName']} is still {$toolStatus} with no run active");
            }
            $toolCallId = (string)($row['toolCallId'] ?? '');
            if ($toolCallId !== '' && $toolCallId !== 'null') {
                if (isset($seenToolCallIds[$toolCallId])) {
                    $report->addViolation(self::CHECK_UNIQUE_TOOL_CALL_IDS, "tool-call id {$toolCallId} at @{$seenToolCallIds[$toolCallId]} and @{$row['indexInConversation']}");
                }
                $seenToolCallIds[$toolCallId] = (int)$row['indexInConversation'];
            }
        }

        $expectedIndex = 0;
        foreach ($messageRows as $row) {
            $rowIndex = (int)$row['indexInConversation'];
            if ($rowIndex > $expectedIndex) {
                $report->addViolation(self::CHECK_CONTIGUOUS_INDEXES, "indexes {$expectedIndex}–" . ($rowIndex - 1) . ' are missing');
            }
            $expectedIndex = max($expectedIndex, $rowIndex + 1);
        }
        return $report;
    }

    protected function addViolation(string $check, string $detail): void
    {
        $this->violations[] = AIConversationIntegrityViolation::forCheckAndDetail($check, $detail);
    }

    /** @param int[] $sortedInitiatorIndexes */
    protected static function nextInitiatorIndexAfter(array $sortedInitiatorIndexes, int $initiatorIndex): int
    {
        foreach ($sortedInitiatorIndexes as $candidateIndex) {
            if ($candidateIndex > $initiatorIndex) {
                return $candidateIndex;
            }
        }
        return PHP_INT_MAX;
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected static function firstModelOutputIndexAfter(array $rows, int $initiatorIndex, int $turnEndIndex): int
    {
        foreach ($rows as $row) {
            $rowIndex = (int)$row['indexInConversation'];
            if ($rowIndex > $initiatorIndex && $rowIndex < $turnEndIndex
                && (($row['role'] ?? null) === AIConversationMessage::ROLE_ASSISTANT || ($row['messageType'] ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL)
            ) {
                return $rowIndex;
            }
        }
        return $turnEndIndex;
    }

    /**
     * Whether a stored capability column LOADS the named capability: a complete set (a checkpoint) that holds it, or a
     * change record with a `load` event for it.
     */
    protected static function capabilityColumnLoads(string $capabilityColumnJson, string $capabilityName): bool
    {
        $storedCapabilities = json_decode($capabilityColumnJson, true);
        if (!is_array($storedCapabilities)) {
            return false;
        }
        if (isset($storedCapabilities['e']) && is_array($storedCapabilities['e'])) {
            foreach ($storedCapabilities['e'] as $changeEvent) {
                if (($changeEvent['op'] ?? null) === 'load' && (($changeEvent['e']['n'] ?? $changeEvent['n'] ?? null) === $capabilityName)) {
                    return true;
                }
            }
            return false;
        }
        foreach ($storedCapabilities as $storedCapability) {
            if (is_array($storedCapability) && (($storedCapability['name'] ?? $storedCapability['n'] ?? null) === $capabilityName)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    protected static function decodeNames(mixed $namesJson): array
    {
        $names = is_string($namesJson) ? json_decode($namesJson, true) : null;
        return is_array($names) ? array_values(array_map('strval', $names)) : [];
    }
}
