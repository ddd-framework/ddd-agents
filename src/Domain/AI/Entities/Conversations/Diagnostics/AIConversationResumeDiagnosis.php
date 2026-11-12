<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The read-only RESUME DIAGNOSIS of one conversation: the decision
 * `resumeConversationIfReady()` would take, reconstructed without driving a turn. It replays the claim preamble's two
 * loads — the summary cover nodes and the BOUNDED derive tail (the raw tail past the cover edge, else the full ordered
 * window) — evaluates the two drive triggers on that window (an unanswered inbound message, the oldest settled-unfolded
 * forked batch) and the same question on the FULL tail: a disagreement between the two tails is the signature of the
 * loop / silent-drop bug. A console command of the application prints it, the admin tool returns it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationResumeDiagnosis extends ValueObject implements LlmMarkdownRenderable
{
    /** The rows of each tail shown (the newest N). */
    public const int TAIL_ROWS_SHOWN = 8;

    /** @var int The conversation. */
    public int $aiConversationId = 0;

    /** @var AIConversationResumeDiagnosisStateRow[] The stored state, one value per row: run state (always the first row), heartbeat, attempts, delegation, model override, budget bypass, message count. */
    public array $stateRows = [];

    /** @var bool True when the run state is non-terminal. */
    public bool $runStateIsNonTerminal = false;

    /** @var AIConversationResumeDiagnosisCoverNode[] The summary cover nodes, in the order they were loaded. */
    public array $coverNodes = [];

    /** @var int|null The index the cover reaches; null = no cover, the whole conversation is raw. */
    public ?int $coveredUpToIndex = null;

    /** @var int Messages in the FULL tail. */
    public int $fullTailMessageCount = 0;

    /** @var AIConversationResumeDiagnosisTailRow[] The newest messages of the FULL tail. */
    public array $fullTailRows = [];

    /** @var int Messages in the BOUNDED derive tail. */
    public int $deriveTailMessageCount = 0;

    /** @var AIConversationResumeDiagnosisTailRow[] The newest messages of the derive tail. */
    public array $deriveTailRows = [];

    public bool $hasUnansweredInboundOnDeriveTail = false;

    public bool $hasUnansweredInboundOnFullTail = false;

    /** @var int|null The oldest forked batch whose results are settled but not folded into the parent. */
    public ?int $settledUnfoldedBatchId = null;

    public bool $hasUnsettledBatch = false;

    /** The diagnosis of a conversation (loads its messages and its summary cover; mutates nothing). */
    public static function buildFor(AIConversation $conversation, AIConversationDelegationBatchesService $batchesService): static
    {
        $diagnosis = new static();
        $diagnosis->aiConversationId = (int)$conversation->id;
        // The FULL ordered message set, independent of any cover bound — the ground truth the derive tail is compared to.
        $fullMessages = $conversation->findMessagesInOrder(useEntityRegistrCache: false) ?? AIConversationMessages::newInstance();
        $diagnosis->runStateIsNonTerminal = $conversation->getRunState()->isNonTerminal();
        $diagnosis->stateRows = [
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('runState', (string)$conversation->runState),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('runHeartbeatAt', isset($conversation->runHeartbeatAt) ? (string)$conversation->runHeartbeatAt : '—'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('resumeAttempts', (string)$conversation->resumeAttempts),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('isDelegation', $conversation->isDelegation() ? 'YES (child)' : 'no (root dialog)'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('delegationBatchId', ($conversation->delegationBatchId ?? null) !== null ? (string)$conversation->delegationBatchId : '—'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('aiModelNameOverride', $conversation->aiModelNameOverride ?? '— (default)'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('budgetBypass', $conversation->budgetBypass ? 'YES' : 'no'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('messages (FULL tail)', (string)count($fullMessages->getElements())),
        ];
        // 1. The claim preamble: the cover, then the BOUNDED derive tail — the same two loads the resume path makes.
        $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
        $diagnosis->coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex();
        foreach ($conversation->compactionNodes->getElements() as $compactionNode) {
            $diagnosis->coverNodes[] = AIConversationResumeDiagnosisCoverNode::forCompactionNode($compactionNode);
        }
        $deriveTail = static::loadDeriveTail($conversation, $diagnosis->coveredUpToIndex);
        $diagnosis->fullTailMessageCount = count($fullMessages->getElements());
        $diagnosis->fullTailRows = array_slice(static::buildTailRows($fullMessages), -self::TAIL_ROWS_SHOWN);
        $diagnosis->deriveTailMessageCount = count($deriveTail->getElements());
        $diagnosis->deriveTailRows = array_slice(static::buildTailRows($deriveTail), -self::TAIL_ROWS_SHOWN);
        // 2. The two drive triggers on the derive window, and the same question on the FULL tail.
        $conversation->messages = $deriveTail;
        $diagnosis->hasUnansweredInboundOnDeriveTail = $conversation->hasUnansweredInboundMessage();
        $fullTailConversation = clone $conversation;
        $fullTailConversation->messages = $fullMessages;
        $diagnosis->hasUnansweredInboundOnFullTail = $fullTailConversation->hasUnansweredInboundMessage();
        $diagnosis->settledUnfoldedBatchId = $batchesService->findOldestBatchIdWithUnreadChildResultsForParent((int)$conversation->id);
        $diagnosis->hasUnsettledBatch = $batchesService->hasUnfinishedBatchForParent((int)$conversation->id);
        return $diagnosis;
    }

    /**
     * The BOUNDED derive tail — a verbatim copy of {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::findContextTailMessages()}:
     * the raw tail past the cover edge when compacted, else the full ordered window. Kept in sync with that method by
     * hand (it is protected on the service); the whole diagnosis hinges on replaying it exactly.
     */
    protected static function loadDeriveTail(AIConversation $conversation, ?int $coveredUpToIndex): AIConversationMessages
    {
        return ($coveredUpToIndex !== null
            ? $conversation->findRawTailMessagesInOrder($coveredUpToIndex, useEntityRegistrCache: false)
            : $conversation->findMessagesInOrder(useEntityRegistrCache: false))
            ?? AIConversationMessages::newInstance();
    }

    /** @return AIConversationResumeDiagnosisTailRow[] One per message, in the order of the messages */
    protected static function buildTailRows(AIConversationMessages $messages): array
    {
        $tailRows = [];
        foreach ($messages->getElements() as $message) {
            $tailRows[] = AIConversationResumeDiagnosisTailRow::forMessage($message);
        }
        return $tailRows;
    }

    /** True when the two tails disagree on an unanswered inbound message — the loop / silent-drop signature. */
    public function tailsDisagree(): bool
    {
        return $this->hasUnansweredInboundOnDeriveTail !== $this->hasUnansweredInboundOnFullTail;
    }

    /** True when the resume path would drive a turn (a trigger is present on the derive window). */
    public function wouldDrive(): bool
    {
        return $this->hasUnansweredInboundOnDeriveTail || $this->settledUnfoldedBatchId !== null;
    }

    public function toLlmMarkdown(): string
    {
        $lines = ["# Resume diagnosis of conversation {$this->aiConversationId}", ''];
        foreach ($this->stateRows as $stateRow) {
            $lines[] = "- {$stateRow->label}: {$stateRow->value}";
        }
        $lines[] = '';
        $lines[] = $this->coveredUpToIndex === null
            ? 'No summary cover — the whole conversation is raw; the derive tail is the full window.'
            : 'Summary cover reaches index ' . $this->coveredUpToIndex . ' (' . count($this->coverNodes) . ' nodes); the derive tail is the raw messages past it.';
        foreach ($this->coverNodes as $coverNode) {
            $lines[] = sprintf(
                '- cover node level %s: messages %s–%s (%s messages, %s source tokens → %s summary tokens)',
                ...$coverNode->getPrintedValues()
            );
        }
        $lines[] = "Full tail: {$this->fullTailMessageCount} messages; derive tail: {$this->deriveTailMessageCount} messages.";
        $lines[] = '- unanswered inbound message on the DERIVE tail: ' . ($this->hasUnansweredInboundOnDeriveTail ? 'yes' : 'no');
        $lines[] = '- unanswered inbound message on the FULL tail: ' . ($this->hasUnansweredInboundOnFullTail ? 'yes' : 'no');
        $lines[] = '- oldest settled-unfolded forked batch: ' . ($this->settledUnfoldedBatchId ?? '— (none)');
        $lines[] = '- any unsettled forked batch: ' . ($this->hasUnsettledBatch ? 'yes' : 'no');
        if ($this->tailsDisagree()) {
            $lines[] = '';
            $lines[] = 'DISAGREEMENT: the bounded derive tail hides an unanswered inbound message the full tail ends on — the cover edge is at or after the newest unanswered owner message, so the resume path treats the conversation as answered (the loop / silent-drop bug).';
        }
        $lines[] = '';
        $lines[] = $this->wouldDrive()
            ? 'Verdict: WOULD DRIVE a turn (a trigger is present on the derive window).'
            : 'Verdict: no work on re-derive — the resume releases ' . ($this->hasUnsettledBatch ? 'AWAITING_DELEGATES' : 'IDLE') . ' and re-fires only if work is owed on reload.';
        $lines[] = '';
        $lines[] = 'Newest rows of the derive tail (index · role/type · preview):';
        foreach ($this->deriveTailRows as $deriveTailRow) {
            $lines[] = "- {$deriveTailRow->indexInConversation} · {$deriveTailRow->roleAndMessageType} · {$deriveTailRow->messagePreview}";
        }
        return implode("\n", $lines);
    }
}
