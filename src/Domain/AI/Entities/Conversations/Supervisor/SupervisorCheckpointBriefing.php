<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;

/**
 * The supervisor checkpoint briefing — the seed USER content a tandem's checkpoint decides over, the BOOKKEEPER briefing, composed ONLY from persisted rows
 * and what {@see SupervisorCheckpointBriefingInputs} gathered from the world. Three blocks after the header:
 *
 *  STATIC (a full briefing only — the first checkpoint of a tandem, byte-stable = the cache prefix): the purpose's
 *  contract digest (every seed item with its gates), its pitfalls and done criteria, the tool digest, the result-marker
 *  legend, the `askOwner` catalogue and the widget types.
 *
 *  DELTA (every checkpoint, the rows after the header's since index): the owner's rows verbatim (text, clicks, the
 *  form), the agent's final text whole, the LEDGER (`[index] name(args) → status · result head`; reads as counts
 *  except the grounding reads), every FAILED call with its reason, the `askOwner` question with the owner's answer,
 *  the widgets the tools rendered, the task plan with the items that changed marked, and the platform's signals.
 *
 *  AUDIT (a settled beat: every owner row, the audit renders, the state of the space, the FACTS, the criteria; a step beat:
 *  the FACTS only), closed by a compact cumulative tool tally (compaction-safe: the cover nodes' counts).
 *
 * Also holds the text helpers every supervisor text shares: a cut that SAYS it is a cut, and whitespace collapsed.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointBriefing extends ValueObject
{
    /** The marker every briefing-side cut carries — the supervisor prompt names it as "abridged by the briefing, not by the agent". */
    public const string BRIEFING_ABRIDGED_MARKER = ' […abridged for this briefing]';

    /**
     * @var int The TAIL's newest agent message rides (nearly) whole — it is the message the verdict judges — but
     * never unbounded: a long sheet of drafts runs to tens of thousands of characters, and every briefing is a
     * persisted seed message the tandem re-reads on every later checkpoint.
     */
    public const int NEWEST_AGENT_TEXT_MAX_CHARS = 8000;

    public const int LEDGER_ARGS_MAX_CHARS = 600;
    public const int LEDGER_RESULT_MAX_CHARS = 300;
    public const int OWNER_ROW_MAX_CHARS = 1500;
    public const int WIDGET_MAX_CHARS = 700;
    public const int EARLIER_AGENT_TEXT_MAX_CHARS = 1500;

    /** The kinds a delta row is rendered as ({@see findDeltaRowKind()}). */
    protected const string DELTA_ROW_OWNER = 'owner';
    protected const string DELTA_ROW_AGENT_TEXT = 'agentText';
    protected const string DELTA_ROW_WIDGET = 'widget';
    protected const string DELTA_ROW_SIGNAL = 'signal';
    protected const string DELTA_ROW_TOOL_CALL = 'toolCall';

    public SupervisorCheckpointBriefingHeader $header;

    public SupervisorCheckpointBriefingInputs $inputs;

    /** The supervised conversation whose loaded rows the briefing renders. */
    protected AIConversation $supervisedConversation;

    /** A briefing of the supervised conversation under `$header`, over what the service gathered. */
    public static function buildFor(SupervisorCheckpointBriefingHeader $header, AIConversation $supervisedConversation, SupervisorCheckpointBriefingInputs $inputs): static
    {
        $supervisorCheckpointBriefing = new static();
        $supervisorCheckpointBriefing->header = $header;
        $supervisorCheckpointBriefing->supervisedConversation = $supervisedConversation;
        $supervisorCheckpointBriefing->inputs = $inputs;
        return $supervisorCheckpointBriefing;
    }

    /** The briefing text: the header lines, the static block of a full briefing, the delta, the audit, the totals. */
    public function render(): string
    {
        $asOfMessageIndex = (int)$this->header->asOfMessageIndex;
        $messages = array_values(array_filter(
            $this->supervisedConversation->messages->getElements(),
            static fn (AIConversationMessage $m): bool => ($m->indexInConversation ?? 0) <= $asOfMessageIndex
        ));
        $sinceMessageIndex = $this->header->isDelta() ? $this->header->sinceMessageIndex : null;
        $sections = [$this->header->renderHeaderLines()];
        if ($sinceMessageIndex === null) {
            foreach ($this->renderStaticSections() as $staticSection) {
                $sections[] = $staticSection;
            }
        }
        foreach ($this->renderDeltaSections($messages, $sinceMessageIndex ?? -1) as $deltaSection) {
            $sections[] = $deltaSection;
        }
        $sections[] = self::renderPlanSection($this->inputs->taskPlan, $this->inputs->taskPlanIsTheStoredPlan, $this->inputs->previousTaskPlan, $sinceMessageIndex);
        $signalLines = [];
        foreach ($this->filterDeltaRows($messages, $sinceMessageIndex ?? -1, self::DELTA_ROW_SIGNAL) as $message) {
            $signalLines[] = '[' . ($message->indexInConversation ?? 0) . '] ' . ($message->signalSource ?? '?') . ': '
                . self::abridgeForBriefing(self::collapseWhitespace(trim((string)($message->message ?? ''))), 240);
        }
        if ($signalLines !== []) {
            $sections[] = "## PLATFORM SIGNALS in this range\n" . implode("\n", array_slice($signalLines, -14));
        }
        if ($this->header->isStepBeat()) {
            // the step delta shows a few owner rows: the deterministic grounding over the WHOLE conversation is the
            // only basis for an "ungrounded" verdict there
            if ($this->inputs->factsSection !== '') {
                $sections[] = $this->inputs->factsSection;
            }
        } else {
            foreach ($this->renderAuditSections() as $auditSection) {
                $sections[] = $auditSection;
            }
        }
        $sections[] = $this->renderCumulativeTallySection($messages);
        return implode("\n\n", $sections);
    }

    /** @return string[] The static block: contract, pitfalls, done criteria, tools, result markers, catalogue, widget types. */
    protected function renderStaticSections(): array
    {
        $sections = [];
        $purpose = $this->inputs->purpose;
        if ($purpose !== null) {
            $contractLines = [];
            $itemNo = 0;
            foreach ($purpose->buildSeedTaskPlanDefinition() as $seedItem) {
                if (!is_array($seedItem)) {
                    continue;
                }
                $itemNo++;
                $contractLines[] = self::renderSeedContractLine((string)$itemNo, $seedItem);
                $subNo = 0;
                foreach ($seedItem['subtasks'] ?? [] as $subItem) {
                    if (!is_array($subItem)) {
                        continue;
                    }
                    $subNo++;
                    $contractLines[] = '  ' . self::renderSeedContractLine($itemNo . '.' . $subNo, $subItem);
                }
            }
            if ($contractLines !== []) {
                $sections[] = "## CONTRACT (the purpose's plan with its gates — `done:` names what closes a leaf)\n" . implode("\n", $contractLines);
            }
            $pitfallLines = [];
            foreach ($purpose->commonPitfalls as $pitfall) {
                $pitfallLines[] = '- ' . $pitfall;
            }
            if ($pitfallLines !== []) {
                $sections[] = "## PITFALLS of this purpose\n" . implode("\n", $pitfallLines);
            }
            if (trim($purpose->doneCriteria) !== '') {
                $sections[] = "## DONE CRITERIA\n" . trim($purpose->doneCriteria);
            }
        }
        if ($this->inputs->toolDigestLines !== []) {
            $sections[] = "## TOOLS (name — what it does; a write RECORDS, a read never does)\n" . implode("\n", $this->inputs->toolDigestLines);
        }
        $sections[] = "## RESULT MARKERS (in a tool result head)\n"
            . '- `' . ToolCall::RESULT_MARKER_NO_WORK_DONE . "` — the call completed but changed nothing (the platform answered instead of refusing)\n"
            . '- `' . ToolCall::RESULT_MARKER_PLATFORM_STORED . "` — the platform recorded the owner's answer itself\n"
            . '- `' . ToolCall::RESULT_MARKER_OWNER_CLAIM_NOT_ACCEPTED . "` — a claimed owner statement was NOT recorded (the owner never said it)\n"
            . '- `' . ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL . "` — a goal was stored as the agent's proposal, not the owner's goal\n"
            . '- `' . ToolCall::RESULT_MARKER_FORM_DECIDED . "` — a form the owner submitted decided the field (the owner's form answer wins)\n"
            . "- `PARTIAL SAVE` / `NOTHING CHANGED` — a write that stored some / none of its values (read the result)\n"
            . "- `REFUSED` / `REJECTED` — the call did not run (the reason follows); `already persisted exactly this` — a duplicate write, nothing new\n"
            . "- `closed by the platform` — a plan leaf the platform closed from the record, not the model";
        $catalogueLines = [];
        foreach (AskOwnerQuestionCatalogue::getQuestions() as $questionKey => $meanings) {
            $catalogueLines[] = "- {$questionKey}: " . implode(' | ', $meanings);
        }
        $sections[] = "## askOwner CATALOGUE (a pick on a catalogue question is recorded by the platform — no write owed for it)\n" . implode("\n", $catalogueLines);
        if ($this->inputs->ownerWidgetTypesDigest !== '') {
            $sections[] = "## WIDGETS (TOOL_MESSAGE rows a tool renders; the owner answers ON them)\n" . $this->inputs->ownerWidgetTypesDigest;
        }
        return $sections;
    }

    /**
     * One CONTRACT line of a seed item: `<number>. <text> [<gates>]`, read from the purpose's raw seed array.
     *
     * @param array<string, mixed> $seedItem
     */
    public static function renderSeedContractLine(string $numberPath, array $seedItem): string
    {
        $gates = [];
        if (!empty($seedItem['doneRequiresToolCall'])) {
            $gates[] = 'done: ' . (string)$seedItem['doneRequiresToolCall'];
        }
        if (!empty($seedItem['doneRequiresWidget'])) {
            $gates[] = 'done: widget ' . (string)$seedItem['doneRequiresWidget'] . (!empty($seedItem['doneRequiresWidgetAnswered']) ? ' answered' : '');
        }
        if (!empty($seedItem['doneCriterion'])) {
            $gates[] = 'criterion: ' . (string)$seedItem['doneCriterion'];
        }
        if (!empty($seedItem['blockedRequiresToolCall'])) {
            $gates[] = 'blocked: ' . (string)$seedItem['blockedRequiresToolCall'];
        }
        if (!empty($seedItem['notApplicableRequiresToolCall'])) {
            $gates[] = 'n/a: ' . (string)$seedItem['notApplicableRequiresToolCall'];
        }
        if (!empty($seedItem['skillNames'])) {
            $gates[] = 'skills: ' . implode(', ', (array)$seedItem['skillNames']);
        }
        $text = self::abridgeForBriefing(self::collapseWhitespace((string)($seedItem['text'] ?? '')), 420);
        return "{$numberPath}. {$text}" . ($gates !== [] ? ' [' . implode(' · ', $gates) . ']' : '');
    }

    /**
     * The delta sections over the rows after `$rangeStart`: OWNER, AGENT TEXT, LEDGER, then — when they have lines —
     * FAILED CALLS, OWNER QUESTIONS and the widgets rendered in the range.
     *
     * @param AIConversationMessage[] $messages The rows up to the covered index, in conversation order.
     * @return string[]
     */
    protected function renderDeltaSections(array $messages, int $rangeStart): array
    {
        $ownerLines = [];
        foreach ($this->filterDeltaRows($messages, $rangeStart, self::DELTA_ROW_OWNER) as $message) {
            $sourceNote = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL ? ' (' . ($message->signalSource ?? 'signal') . ')' : '';
            $ownerLines[] = '[' . ($message->indexInConversation ?? 0) . "]{$sourceNote} " . self::abridgeForBriefing(trim((string)($message->message ?? '')), self::OWNER_ROW_MAX_CHARS);
        }
        $newestAgentTextIndex = self::findNewestAgentTextIndex($messages, $rangeStart);
        $agentTextLines = [];
        foreach ($this->filterDeltaRows($messages, $rangeStart, self::DELTA_ROW_AGENT_TEXT) as $message) {
            $index = $message->indexInConversation ?? 0;
            $agentTextLines[] = "[$index] " . self::abridgeForBriefing(
                trim(WidgetBlocks::buildModelFacingText((string)($message->message ?? ''))),
                $index === $newestAgentTextIndex ? self::NEWEST_AGENT_TEXT_MAX_CHARS : self::EARLIER_AGENT_TEXT_MAX_CHARS
            );
        }
        $widgetLines = [];
        foreach ($this->filterDeltaRows($messages, $rangeStart, self::DELTA_ROW_WIDGET) as $message) {
            $widgetLines[] = '[' . ($message->indexInConversation ?? 0) . '] ' . self::abridgeForBriefing(self::collapseWhitespace(trim(WidgetBlocks::buildModelFacingText((string)($message->message ?? '')))), self::WIDGET_MAX_CHARS);
        }
        $toolCallRows = $this->filterDeltaRows($messages, $rangeStart, self::DELTA_ROW_TOOL_CALL);
        $failedLines = self::renderFailedCallLines($toolCallRows);
        $askOwnerLines = $this->renderAskOwnerLines($toolCallRows, $rangeStart);

        $sections = [];
        $sections[] = "## OWNER (rows in this range, verbatim)\n" . implode("\n", $ownerLines !== [] ? $ownerLines : ['(none)']);
        $sections[] = "## AGENT TEXT (what the owner read; the newest whole)\n" . implode("\n\n", $agentTextLines !== [] ? $agentTextLines : ['(none — the turn is still running)']);
        $sections[] = $this->renderLedgerSection($toolCallRows);
        if ($failedLines !== []) {
            $sections[] = "## FAILED CALLS (the actual reason — act on it, never guess an argument problem)\n" . implode("\n", $failedLines);
        }
        if ($askOwnerLines !== []) {
            $sections[] = "## OWNER QUESTIONS (askOwner)\n" . implode("\n", $askOwnerLines);
        }
        if ($widgetLines !== []) {
            $sections[] = "## WIDGETS the platform rendered in this range\n" . implode("\n", $widgetLines);
        }
        return $sections;
    }

    /**
     * What a row after `$rangeStart` is rendered as, first match wins: an owner-authored row, a non-empty agent text,
     * a non-empty widget row, a signal, a named tool call; null = not rendered (at or before the range start, or none
     * of these).
     */
    protected function findDeltaRowKind(AIConversationMessage $message, int $rangeStart): ?string
    {
        if (($message->indexInConversation ?? 0) <= $rangeStart) {
            return null;
        }
        $text = trim((string)($message->message ?? ''));
        $messageType = $message->messageType ?? null;
        if ($message->isOwnerAuthoredInbound()) {
            return self::DELTA_ROW_OWNER;
        }
        if ($message->isAssistantText() && $text !== '') {
            return self::DELTA_ROW_AGENT_TEXT;
        }
        if ($messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE && $text !== '') {
            return self::DELTA_ROW_WIDGET;
        }
        if ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
            return self::DELTA_ROW_SIGNAL;
        }
        $toolCall = $message->toolCall ?? null;
        if ($toolCall === null || ($toolCall->name ?? '') === '') {
            return null;
        }
        return self::DELTA_ROW_TOOL_CALL;
    }

    /**
     * @param AIConversationMessage[] $messages
     * @return AIConversationMessage[] The rows after `$rangeStart` of the delta row kind `$deltaRowKind`, in order.
     */
    protected function filterDeltaRows(array $messages, int $rangeStart, string $deltaRowKind): array
    {
        return array_values(array_filter(
            $messages,
            fn (AIConversationMessage $message): bool => $this->findDeltaRowKind($message, $rangeStart) === $deltaRowKind
        ));
    }

    /**
     * The newest non-empty agent text after `$rangeStart` (null = none) — the message the verdict judges, rendered at
     * {@see NEWEST_AGENT_TEXT_MAX_CHARS}.
     *
     * @param AIConversationMessage[] $messages
     */
    protected static function findNewestAgentTextIndex(array $messages, int $rangeStart): ?int
    {
        $newestAgentTextIndex = null;
        foreach ($messages as $message) {
            if (($message->indexInConversation ?? 0) > $rangeStart
                && $message->isAssistantText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                $newestAgentTextIndex = $message->indexInConversation ?? $newestAgentTextIndex;
            }
        }
        return $newestAgentTextIndex;
    }

    /**
     * @param AIConversationMessage[] $toolCallRows
     * @return string[] Every failed or rejected call of the range with the head of its reason.
     */
    protected static function renderFailedCallLines(array $toolCallRows): array
    {
        $failedLines = [];
        foreach ($toolCallRows as $message) {
            $toolCall = $message->toolCall;
            if ($toolCall->isFailedOrRejected()) {
                $failedLines[] = '[' . ($message->indexInConversation ?? 0) . "] {$toolCall->name}(" . self::abridgeArguments($toolCall) . '): '
                    . self::abridgeForBriefing(self::collapseWhitespace(mb_substr((string)($toolCall->result ?? ''), 0, 1200)), 400);
            }
        }
        return $failedLines;
    }

    /**
     * The `askOwner` questions of the range and, after them, the owner's pick on the newest keyed question widget
     * (when it stands in the range) or the note that the range holds no pick.
     *
     * @param AIConversationMessage[] $toolCallRows
     * @return string[]
     */
    protected function renderAskOwnerLines(array $toolCallRows, int $rangeStart): array
    {
        $askOwnerLines = [];
        foreach ($toolCallRows as $message) {
            $toolCall = $message->toolCall;
            if ($toolCall->name !== AskOwnerTool::NAME) {
                continue;
            }
            $askOwnerLines[] = '[' . ($message->indexInConversation ?? 0) . '] askOwner' . (isset($toolCall->arguments['questionKey']) ? '[' . (string)$toolCall->arguments['questionKey'] . ']' : '')
                . ': ' . self::abridgeForBriefing((string)($toolCall->arguments['question'] ?? ''), 300)
                . ' — options: ' . self::abridgeForBriefing(self::renderAskOwnerOptions($toolCall->arguments['options'] ?? []), 300);
        }
        $keyedQuestionWidget = AskOwnerTool::findNewestShownOwnerQuestionWidget($this->supervisedConversation->messages->buildShownWidgets());
        $askOwnerPick = $keyedQuestionWidget !== null ? AskOwnerTool::readOwnerPickOnShownWidget($keyedQuestionWidget) : null;
        if ($keyedQuestionWidget !== null && $askOwnerPick !== null && $askOwnerLines !== [] && $keyedQuestionWidget->hostIndexInConversation > $rangeStart) {
            $askOwnerLines[] = "owner's pick on the widget [{$keyedQuestionWidget->hostIndexInConversation}] at [{$askOwnerPick->reactionIndexInConversation}]: "
                . self::abridgeForBriefing($askOwnerPick->ownerText, 200)
                . ($askOwnerPick->meanings !== [] ? ' (meaning: ' . implode(', ', $askOwnerPick->meanings) . ' — recorded by the platform)' : '');
        } elseif ($askOwnerLines !== []) {
            $askOwnerLines[] = '(no pick on a widget in this range — the owner typed, or has not answered yet)';
        }
        return $askOwnerLines;
    }

    /**
     * The LEDGER: one line per write, grounding read, failed call or refused duplicate of the range (`askOwner` has
     * its own section); every other call counted per tool name, most frequent first.
     *
     * @param AIConversationMessage[] $toolCallRows
     */
    protected function renderLedgerSection(array $toolCallRows): string
    {
        $ledgerLines = [];
        $readCounts = [];
        foreach ($toolCallRows as $message) {
            $toolCall = $message->toolCall;
            $toolName = (string)$toolCall->name;
            if ($toolName === AskOwnerTool::NAME) {
                continue;
            }
            if ($toolCall->changesState() || in_array($toolName, $this->inputs->groundingReadToolNames, true) || $toolCall->isFailedOrRejected() || $toolCall->wasRefusedAsDuplicate) {
                $ledgerLines[] = '[' . ($message->indexInConversation ?? 0) . "] {$toolName}(" . self::abridgeArguments($toolCall) . ') → ' . (string)($toolCall->status ?? '?')
                    . ($toolCall->wasRefusedAsDuplicate ? ' (duplicate read — reused)' : '')
                    . ' · ' . self::abridgeForBriefing(self::collapseWhitespace($toolCall->getResultTextHead(self::LEDGER_RESULT_MAX_CHARS + 40)), self::LEDGER_RESULT_MAX_CHARS);
                continue;
            }
            $readCounts[$toolName] = ($readCounts[$toolName] ?? 0) + 1;
        }
        $readCountParts = [];
        arsort($readCounts);
        foreach ($readCounts as $readToolName => $count) {
            $readCountParts[] = "{$readToolName} x{$count}";
        }
        return "## LEDGER (writes, grounding reads, refusals; `→ status · result head`)\n"
            . implode("\n", $ledgerLines !== [] ? $ledgerLines : ['(no write or grounding read in this range)'])
            . "\nother reads and plan ticks: " . ($readCountParts !== [] ? implode(', ', $readCountParts) : '(none)');
    }

    /**
     * The task plan STATE as of the covered index; with a since-index the items whose status changed are marked `*`.
     *
     * A briefing AT the newest row (the live tandem) shows the STORED plan: the replay applies only the agent's own
     * ticks, so every leaf the platform closed from the record (an n/a from an owner statement, a done from a widget
     * click) stayed open in it — the supervisor then read a finished purpose as half-done and called its closing step
     * premature. Historical replays (eval commands at an earlier index) keep the replay.
     */
    public static function renderPlanSection(?TaskPlan $taskPlan, bool $isTheStoredPlan, ?TaskPlan $previousTaskPlan, ?int $sinceMessageIndex): string
    {
        if ($taskPlan?->items === null) {
            return "## PLAN\n(no task plan)";
        }
        if ($isTheStoredPlan) {
            $sinceMessageIndex = null; // no stored history of earlier statuses — the whole current plan, no `*` marks
        }
        $previousStatuses = [];
        if ($sinceMessageIndex !== null) {
            // every depth of the tree (no depth limit), keyed by the item KEY: a restructure marks exactly the items whose status changed
            foreach ($previousTaskPlan?->items?->buildItemAddresses()->getElements() ?? [] as $previousItemAddress) {
                $previousStatuses[$previousItemAddress->key] = $previousItemAddress->item->status;
            }
        }
        // A delta renders the changed items and the first open leaf in full (the first checkpoint sent the contract
        // digest with every item's text and gates); every other item is one compact line.
        $planLines = [];
        $changed = 0;
        $nextOpenRendered = false;
        // every depth of the tree, pre-order, two spaces of indent per level
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $planItemAddress) {
            $planItem = $planItemAddress->item;
            $numberPath = $planItemAddress->key;
            $indent = str_repeat('  ', $planItemAddress->depth);
            $statusChanged = $sinceMessageIndex !== null && ($previousStatuses[$numberPath] ?? null) !== $planItem->status;
            $changed += $statusChanged ? 1 : 0;
            $isNextOpen = !$nextOpenRendered && $planItem->status === TaskPlanItem::STATUS_OPEN && $planItemAddress->isLeaf;
            if ($isNextOpen) {
                $nextOpenRendered = true;
            }
            if ($sinceMessageIndex === null || $statusChanged || $isNextOpen) {
                $planLines[] = $indent . ($statusChanged ? '* ' : '') . ($isNextOpen ? '← next ' : '') . $planItem->renderSupervisorPlanLine($numberPath);
                continue;
            }
            $planLines[] = $indent . "{$numberPath}. [{$planItem->status}] " . self::abridgeForBriefing(self::collapseWhitespace($planItem->text), 70);
        }
        if ($isTheStoredPlan) {
            return "## PLAN (the stored plan now — platform-closed leaves included; `← next` = the first open leaf)\n" . implode("\n", $planLines);
        }
        return "## PLAN (as of this index" . ($sinceMessageIndex !== null ? "; `*` = status changed since the last checkpoint, {$changed} item(s); `← next` = the first open leaf; the rest compact — the CONTRACT holds the full text)" : ')') . "\n" . implode("\n", $planLines);
    }

    /**
     * The AUDIT blocks a SETTLED beat carries beside the delta (what the judge can see,
     * the supervisor must see): every owner row of the conversation verbatim, the widgets the owner saw, the write
     * ledger with full arguments, the outward actions, the task plan with provenance, the live state of the space, the
     * FACTS, and the purpose's audit criteria the checkpoint must fill.
     *
     * @return string[]
     */
    protected function renderAuditSections(): array
    {
        $sections = [];
        $ownerLines = [];
        foreach ($this->supervisedConversation->messages->getOwnerAuthoredMessagesUpToIndex((int)$this->header->asOfMessageIndex) as $message) {
            $sourceNote = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL ? ' (' . ($message->signalSource ?? 'signal') . ')' : '';
            $ownerLines[] = '[' . ($message->indexInConversation ?? 0) . "]{$sourceNote} " . self::abridgeForBriefing(self::collapseWhitespace((string)($message->message ?? '')), 800);
        }
        $sections[] = "## ALL OWNER ROWS of the conversation (verbatim — the ground truth for every value)\n" . implode("\n", $ownerLines !== [] ? $ownerLines : ['(none)']);
        $sections[] = '## ' . $this->inputs->widgetInventoryText;
        $sections[] = '## ' . $this->inputs->writeLedgerText;
        $sections[] = '## ' . $this->inputs->outwardActionsText;
        if ($this->inputs->taskPlanWithProvenanceText !== null) {
            $sections[] = '## ' . $this->inputs->taskPlanWithProvenanceText;
        }
        $agentSpaceStateText = $this->inputs->agentSpaceStateText;
        if ($agentSpaceStateText !== null && trim($agentSpaceStateText) !== '') {
            $sections[] = '## ' . $this->inputs->agentSpaceStateHeadline . "\n" . $agentSpaceStateText;
        }
        if ($this->inputs->factsSection !== '') {
            $sections[] = $this->inputs->factsSection;
        }
        if ($this->inputs->auditCriteria !== '') {
            $sections[] = "## AUDIT CRITERIA (check every line in your thinking; name the violated codes in your call — HARD ones first)\n" . $this->inputs->auditCriteria;
        }
        return $sections;
    }

    /**
     * The compact cumulative tally (compaction-safe: the cover nodes' counts) — the complete evidence of what ever ran.
     *
     * @param AIConversationMessage[] $messages The rows up to the covered index.
     */
    protected function renderCumulativeTallySection(array $messages): string
    {
        $completedTally = [];
        foreach ($messages as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null && ($toolCall->name ?? '') !== '' && AIConversationCompactionToolTelemetry::isCountedCompletion($toolCall)) {
                $completedTally[$toolCall->name] = ($completedTally[$toolCall->name] ?? 0) + 1;
            }
        }
        $coverToolTelemetry = $this->inputs->coverToolTelemetry;
        foreach ($coverToolTelemetry->getCompletedCountsByToolName() as $toolName => $coveredCount) {
            $completedTally[$toolName] = ($completedTally[$toolName] ?? 0) + $coveredCount;
        }
        arsort($completedTally);
        $parts = [];
        foreach ($completedTally as $toolName => $count) {
            $parts[] = "{$toolName} x{$count}";
        }
        $coverageNote = $coverToolTelemetry->countsIncomplete
            ? ' (INCOMPLETE: part of the history was summarised before the counts existed — an absent tool may still have run; verify with readRecord, never conclude "never ran")'
            : ' (complete — a tool absent here never completed)';
        return "## TOTALS (completed work calls over the whole conversation{$coverageNote})\n" . ($parts !== [] ? implode(', ', $parts) : '(none)')
            . "\nloaded skills: " . implode(', ', $this->supervisedConversation->messages->getLoadedSkillNames() ?: ['(none)']);
    }

    /**
     * Cut a text to $maxChars and SAY SO: a bare `mb_substr` leaves the supervisor unable to tell a briefing cut from an
     * agent message that really ended mid-sentence. Unchanged when it fits.
     */
    public static function abridgeForBriefing(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxChars)) . self::BRIEFING_ABRIDGED_MARKER;
    }

    /** Every whitespace run as one space, trimmed. */
    public static function collapseWhitespace(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    /** A call's arguments as the ledger shows them ({@see LEDGER_ARGS_MAX_CHARS}, the cut marked). */
    public static function abridgeArguments(ToolCall $toolCall): string
    {
        return $toolCall->renderAbridgedArguments(self::LEDGER_ARGS_MAX_CHARS, self::BRIEFING_ABRIDGED_MARKER);
    }

    /** The labels of an `askOwner` option list, `|`-joined (empty labels and non-list values dropped). */
    public static function renderAskOwnerOptions(mixed $options): string
    {
        if (!is_array($options)) {
            return '';
        }
        $labels = [];
        foreach ($options as $option) {
            $labels[] = is_array($option) ? (string)($option['label'] ?? '') : (string)$option;
        }
        return implode(' | ', array_filter($labels, static fn (string $label): bool => $label !== ''));
    }
}
