<?php
declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Services\DDDService;

/**
 * What ONE turn of the agent left behind on the record, read from the turn's persisted rows — the platform's own
 * account of the turn, never the model's. Composed at the two moments the model has to go on without its usual
 * footing: the budget closing step (the model writes the owner's message from what it has) and the resume after a
 * pause (the model continues without re-reading). Every entry names the row index it was read from, so the judge
 * and the admin inspector can follow it; the block is rendered under a heading that says it is the platform's
 * record, not owner speech.
 *
 * Buckets, decided from the tool-call rows alone:
 *  - saved: a COMPLETED call of a tool not declared a read ({@see ToolCall::isReadOnly()}) whose facts do not say
 *    the write stayed out (`saved=no`, a BLOCKED outcome) — with the facts the tool preserved, else the result's head;
 *  - validatedNotSaved: a COMPLETED call whose facts say `saved=no` (a value validated, not stored);
 *  - blocked: a COMPLETED call whose `outcome` starts with BLOCKED — the write did not happen, the result says why;
 *  - refused: a FAILED call (a gate, a contract) or a write that said `applied: false` — the head of the refusal names
 *    the rule; a later save of the same tool settles an `applied: false` entry;
 *  - widgets: TOOL_MESSAGE rows (a widget a tool wrote into the chat) with their widget type;
 *  - reads: COMPLETED calls of read tools — their results still hold, the model need not read again;
 *  - ownerInput: the owner rows of the turn (a correction, an approval) — quoted, never interpreted.
 *
 * @phpstan-consistent-constructor
 */
class AgentPausedTurnRecord extends ValueObject
{
    /** The heading every rendering starts with — the one line a reader needs to know whose words these are. */
    public const string HEADING = 'PLATFORM RECORD OF THE TURN — the platform\'s account of the rows, not owner speech, not approval';

    /** The loop's own tools: never on the record (a resumed turn must still call check_delegations). */
    public const array AGENT_TOOL_NAMES = ['load_skills', 'delegate', 'check_delegations', 'send_owner_report'];

    /** Rendering caps: the record is read on every resumed step, so it stays short; the blockers are never cut. */
    public const int MAX_LINES_PER_BUCKET = 6;
    public const int MAX_FACT_CHARS = 160;
    public const int MAX_HEAD_CHARS = 140;

    /** @var AgentPausedTurnRecordedToolCall[] The writes that landed, in the order of the rows */
    public array $saved = [];
    /** @var AgentPausedTurnRecordedToolCall[] The calls whose result says `saved=no`, in the order of the rows */
    public array $validatedNotSaved = [];
    /** @var AgentPausedTurnRecordedToolCall[] The calls whose outcome starts with BLOCKED, in the order of the rows */
    public array $blocked = [];
    /** @var AgentPausedTurnRecordedToolCall[] The failed calls and the writes that answered `applied: false`, in the order of the rows */
    public array $refused = [];
    /** @var AgentPausedTurnRecordedWidget[] The widgets tools wrote into the chat, in the order of the rows */
    public array $widgets = [];
    /** @var AgentPausedTurnRecordedRead[] The completed calls of read tools, newest last */
    public array $reads = [];
    /** @var AgentPausedTurnRecordedOwnerInput[] The owner's messages of the turn, in the order of the rows */
    public array $ownerInput = [];
    /** @var string|null The owner-facing title of the plan's next open item, when the plan has one */
    public ?string $planNextTitle = null;

    /**
     * Compose from the rows of ONE turn: the initiator row (the owner's message or the work signal) and everything
     * the loop appended after it, in index order. Pure; no repo.
     *
     * @param AIConversationMessage[] $turnRows index-sorted ascending, the initiator first
     */
    public static function composeFromTurnRows(array $turnRows, ?TaskPlan $taskPlan = null): static
    {
        $record = new static();
        foreach ($turnRows as $row) {
            $rowIndex = (int)($row->indexInConversation ?? -1);
            if ($row->isOwnerAuthoredInbound()) {
                $record->ownerInput[] = AgentPausedTurnRecordedOwnerInput::forOwnerMessage($rowIndex, self::cut(trim((string)($row->message ?? '')), self::MAX_HEAD_CHARS));
                continue;
            }
            if (($row->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
                foreach (self::widgetTypesOf((string)($row->message ?? '')) as $widgetType) {
                    $record->widgets[] = AgentPausedTurnRecordedWidget::forWidgetOfMessage($widgetType, $rowIndex);
                }
                continue;
            }
            $toolCall = isset($row->toolCall) ? $row->toolCall : null;
            if ($toolCall === null || ($row->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL) {
                continue;
            }
            $record->addToolCall($toolCall, $rowIndex);
        }
        $nextItem = $taskPlan?->items?->getNextMarkedItem();
        $record->planNextTitle = $nextItem?->ownerFacingTitle;
        return $record;
    }

    protected function addToolCall(ToolCall $toolCall, int $rowIndex): void
    {
        $toolName = (string)($toolCall->name ?? '');
        if ($toolName === '' || in_array($toolName, TaskPlan::PLAN_TOOL_NAMES, true) || in_array($toolName, self::AGENT_TOOL_NAMES, true)) {
            return; // plan ticks and the loop's own tools are workflow state, not record facts
        }
        $recordedToolCall = AgentPausedTurnRecordedToolCall::forToolCallOfMessage($toolName, $rowIndex);
        if ($toolCall->isError() || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate) {
            $recordedToolCall->text = self::cut(self::firstSentence($toolCall->getResultTextHead(400)), self::MAX_HEAD_CHARS);
            $this->refused[] = $recordedToolCall;
            return;
        }
        if (($toolCall->status ?? null) !== AIConversationMessage::STATUS_COMPLETED) {
            return; // pending, running or cancelled: nothing on the record yet
        }
        if ($toolCall->wasDowngradedToProposal()) {
            // Stored as the assistant's proposal, not as the owner's — never a save
            $recordedToolCall->text = self::cut(self::firstSentence($toolCall->getResultTextHead(400)), self::MAX_HEAD_CHARS);
            $this->refused[] = $recordedToolCall;
            return;
        }
        // The facts the tool preserved (markdown results); a structured result carries them at its top level
        $facts = $toolCall->getResultPreservedFacts() ?? [];
        if ($facts === []) {
            $structuredContent = $toolCall->getResultStructuredContent() ?? [];
            foreach (['outcome', 'saved'] as $factKey) {
                if (isset($structuredContent[$factKey]) && is_scalar($structuredContent[$factKey])) {
                    $facts[$factKey] = $structuredContent[$factKey];
                }
            }
        }
        $outcome = strtoupper((string)($facts['outcome'] ?? ''));
        $savedRaw = $facts['saved'] ?? null;
        $savedFact = is_bool($savedRaw) ? ($savedRaw ? 'yes' : 'no') : strtolower((string)($savedRaw ?? ''));
        if (str_starts_with($outcome, 'BLOCKED')) {
            // What the result told the model to do next is the one thing the record must carry — the result's NEXT line
            $recordedToolCall->text = self::cut(self::nextLine($toolCall) ?? self::firstSentence($toolCall->getResultTextHead(400)), self::MAX_HEAD_CHARS * 2);
            $this->blocked[] = $recordedToolCall;
            return;
        }
        // A write is every tool that is not declared a read (a scheduled task, a build, a mandate)
        if (!$toolCall->isReadOnly()) {
            $structuredContent = $toolCall->getDecodedResult()->structuredContent ?? [];
            if (($structuredContent['applied'] ?? null) === false) {
                // refused by the tool (an owner consent missing, a rejected mutation): never a save — and not
                // "nothing changed" either, a multi-slot write can stop half way; the record carries why
                $recordedToolCall->text = self::cut(self::refusalReason($structuredContent, $toolCall), self::MAX_HEAD_CHARS * 2);
                $recordedToolCall->wasRefusedWithAppliedFalse = true;
                $this->refused[] = $recordedToolCall;
                return;
            }
            $recordedToolCall->text = self::cut(self::factsLine($facts, $toolCall), self::MAX_FACT_CHARS);
            if ($savedFact === 'no') {
                $this->validatedNotSaved[] = $recordedToolCall;
            } else {
                $this->saved[] = $recordedToolCall;
                // a refusal of this tool earlier in the stretch is settled by this save
                $this->refused = array_values(array_filter(
                    $this->refused,
                    static fn (AgentPausedTurnRecordedToolCall $refusedToolCall): bool => !($refusedToolCall->wasRefusedWithAppliedFalse && $refusedToolCall->toolName === $toolName)
                ));
            }
            return;
        }
        if ($savedFact === 'no') {
            $recordedToolCall->text = self::cut(self::factsLine($facts, $toolCall), self::MAX_FACT_CHARS) . ' — the result still holds, do not validate again';
            $this->validatedNotSaved[] = $recordedToolCall; // a value validated, not stored: saved=no
            return;
        }
        $this->reads[] = AgentPausedTurnRecordedRead::forReadOfMessage($toolName, $rowIndex, self::normalisedArguments($toolCall->arguments));
    }

    /** Whether the record holds anything a resumed or closing model call must know. */
    public function isEmpty(): bool
    {
        return $this->saved === [] && $this->validatedNotSaved === [] && $this->blocked === [] && $this->refused === []
            && $this->widgets === [] && $this->reads === [] && $this->ownerInput === [];
    }

    /** Whether a write of the turn did NOT land and the next move is the owner's or the model's (a block, a refusal). */
    public function hasUnresolvedWrite(): bool
    {
        return $this->blocked !== [] || $this->refused !== [] || $this->validatedNotSaved !== [];
    }

    /**
     * The block for a SIGNAL row: heading, then one line per entry, the buckets that must never be cut first. The
     * `RECORD:` prefix on every line keeps a quoted owner sentence from reading as a fresh owner message.
     */
    public function toSignalText(): string
    {
        $lines = [self::HEADING];
        foreach ($this->blocked as $blockedToolCall) {
            $lines[] = "RECORD: {$blockedToolCall->toolName} @{$blockedToolCall->messageIndex} BLOCKED — nothing was changed: {$blockedToolCall->text}";
        }
        foreach ($this->validatedNotSaved as $validatedToolCall) {
            $lines[] = "RECORD: {$validatedToolCall->toolName} @{$validatedToolCall->messageIndex} VALIDATED, NOT SAVED: {$validatedToolCall->text}";
        }
        foreach (array_slice($this->refused, 0, self::MAX_LINES_PER_BUCKET) as $refusedToolCall) {
            $lines[] = "RECORD: {$refusedToolCall->toolName} @{$refusedToolCall->messageIndex} REFUSED: {$refusedToolCall->text}";
        }
        foreach ($this->ownerInput as $recordedOwnerInput) {
            $lines[] = "RECORD: owner @{$recordedOwnerInput->messageIndex} wrote: \"{$recordedOwnerInput->text}\"";
        }
        foreach (array_slice($this->saved, 0, self::MAX_LINES_PER_BUCKET) as $savedToolCall) {
            $lines[] = "RECORD: {$savedToolCall->toolName} @{$savedToolCall->messageIndex} SAVED: {$savedToolCall->text}";
        }
        if (count($this->saved) > self::MAX_LINES_PER_BUCKET) {
            $lines[] = 'RECORD: … and ' . (count($this->saved) - self::MAX_LINES_PER_BUCKET) . ' more saves (their results are above)';
        }
        foreach ($this->widgets as $recordedWidget) {
            $lines[] = "RECORD: widget {$recordedWidget->widgetType} @{$recordedWidget->messageIndex} is in the chat — the owner sees it; one sentence about it is still owed unless already written";
        }
        if ($this->reads !== []) {
            $readLines = [];
            foreach (array_slice($this->reads, -self::MAX_LINES_PER_BUCKET) as $recordedRead) {
                $readLines[] = "{$recordedRead->toolName} @{$recordedRead->messageIndex}" . ($recordedRead->normalisedArguments !== '' ? " ({$recordedRead->normalisedArguments})" : '');
            }
            $lines[] = 'RECORD: reads whose results still hold — do not call again with the same arguments: ' . implode('; ', $readLines);
        }
        if ($this->planNextTitle !== null) {
            $lines[] = "RECORD: the plan's next open item: {$this->planNextTitle}";
        }
        return implode("\n", $lines);
    }

    /**
     * The tool names of the turn's landed writes, in order, deduplicated — the pause report names them by their
     * owner-facing titles.
     *
     * @return string[]
     */
    public function savedWriteToolNames(): array
    {
        $toolNames = [];
        foreach ($this->saved as $savedToolCall) {
            $toolNames[$savedToolCall->toolName] = true;
        }
        return array_keys($toolNames);
    }

    /**
     * The line of a landed write: the VALUE-bearing facts the tool preserved (top-level keys — `saved`, `outcome`,
     * `formatted`, a count), never the nested flag facts of a rendered object (`bookings.first.isConfirmed`); when the
     * facts carry no value, the result's first sentence (the tool's own "Saved: …" line).
     *
     * @param array<string, mixed> $facts
     */
    protected static function factsLine(array $facts, ToolCall $toolCall): string
    {
        $parts = [];
        foreach ($facts as $factKey => $factValue) {
            $factKey = (string)$factKey;
            if (!is_scalar($factValue) || str_contains($factKey, '.') || str_contains($factKey, 'lastRequestPayload') || str_contains($factKey, 'lastRawResponse')) {
                continue;
            }
            $parts[] = $factKey . '=' . (is_bool($factValue) ? ($factValue ? 'yes' : 'no') : (string)$factValue);
            if (count($parts) >= 6) {
                break;
            }
        }
        $resultLine = self::firstSentence($toolCall->getResultTextHead(300));
        if ($parts === []) {
            return $resultLine;
        }
        $factsText = implode(', ', $parts);
        // "Successful response for 'x'" says nothing — the facts carry the line; a real sentence carries the values
        return str_starts_with($resultLine, 'Successful response') || $resultLine === '' ? $factsText : $factsText . ' · ' . $resultLine;
    }

    /**
     * Why a write said `applied: false`: the result's own next step or first violation (a mutation may render no
     * markdown — its text is only "Successful response for …"), else its NEXT line, else its whole rendered text (a
     * consent refusal's text is the instruction).
     *
     * @param array<string, mixed> $structuredContent
     */
    protected static function refusalReason(array $structuredContent, ToolCall $toolCall): string
    {
        $nextStep = is_string($structuredContent['nextStep'] ?? null) ? trim($structuredContent['nextStep']) : '';
        if ($nextStep !== '') {
            return $nextStep;
        }
        $violations = is_array($structuredContent['violations'] ?? null) ? $structuredContent['violations'] : [];
        $firstViolation = $violations !== [] ? reset($violations) : null;
        if (is_string($firstViolation) && trim($firstViolation) !== '') {
            return trim($firstViolation);
        }
        return self::nextLine($toolCall) ?? trim(preg_replace('/\s+/', ' ', $toolCall->getResultTextHead(600)) ?? '');
    }

    /** The result's `NEXT:` instruction (what the model or the owner must do now), or null when the result has none. */
    protected static function nextLine(ToolCall $toolCall): ?string
    {
        $resultText = $toolCall->getResultTextHead(1200);
        if (preg_match('/NEXT:\s*(.+?)(?:\n|$)/u', $resultText, $nextMatch) !== 1) {
            return null;
        }
        return trim(preg_replace('/\s+/', ' ', str_replace(['```', '**'], '', $nextMatch[1])) ?? '');
    }

    /**
     * The arguments without the presentation keys and without the arguments the conversation's space fills
     * ({@see AgentSpacesService::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()} names them), as one
     * short sorted line (the dedup key of a read).
     *
     * @param array<string, mixed> $arguments
     * @return string
     */
    protected static function normalisedArguments(array $arguments): string
    {
        unset($arguments['noCache'], $arguments['outputFormat']);
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        foreach (array_keys($agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()) as $argumentNameFilledFromAgentSpace) {
            unset($arguments[$argumentNameFilledFromAgentSpace]);
        }
        if ($arguments === []) {
            return '';
        }
        ksort($arguments);
        return self::cut((string)json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 80);
    }

    protected static function firstSentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', str_replace(['```', '**', '##'], '', $text)) ?? '');
        if (preg_match('/^(.{20,}?[.!?])(\s|$)/u', $text, $sentenceMatch) === 1) {
            return $sentenceMatch[1];
        }
        return $text;
    }

    protected static function cut(string $text, int $maxChars): string
    {
        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars - 1)) . '…' : $text;
    }

    /**
     * The written types of the message's VALID widget blocks, each once (read by WidgetBlocks
     * instead of the first "type" anywhere up to a ```, letters only). Changes against the old regex: the block's own
     * top-level type (a nested "type" or one in a later non-widget fence no longer counts); names with digits read;
     * `~~~`, bare, one-line and leaf blocks read; a block with invalid JSON no longer counts; a block unclosed at the end
     * of the text counts when its JSON parses. The written type is kept (`…Directive` stays as written) until the
     * canonical-name step.
     *
     * @return string[]
     */
    protected static function widgetTypesOf(string $message): array
    {
        return array_values(array_unique(WidgetBlocks::fromMarkdown($message)->getWrittenWidgetTypes()));
    }
}
