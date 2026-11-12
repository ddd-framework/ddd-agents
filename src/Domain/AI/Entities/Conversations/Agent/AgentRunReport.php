<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOwnerFacingSubject;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The RUN REPORT of an agent run that ended on an abnormal/unattended terminal: the honest, delivered
 * account of WHAT was completed, WHAT remains (as task-plan tree paths), WHICH items are blocked and why (with the
 * `requiresUserAction` call-to-action and the `retryable` "will retry" wording), and WHY the run stopped.
 *
 * STRICTLY TEMPLATE-BASED — ZERO model calls (on the budget terminal any model call
 * re-throws AiBudgetExceededException, the exact poison loop that forced the finalize handler's budget exemption).
 * The summary outcome is read ONLY when already loaded (`isset` — the summary is an Argus READ-THROUGH cache whose
 * lazy-load IS a generation; the composer must never trigger it). ValueObject, transient — the delivered TEXT is
 * what persists (as the terminal chat message); the obligation bookkeeping lives on
 * {@see AIConversation::$reportOwedAt}/{@see AIConversation::$reportDeliveredAt}.
 *
 * TRANSLATION: one app translation key per rendered LINE, every dynamic part a `%placeholder%` — word order and
 * punctuation belong to the translation, never to code. Count-dependent lines are one key whose plural wording is
 * selected through the MANY context ({@see translatePlural}); only list joiners (`; `, ` | `) stay in code.
 *
 * @phpstan-consistent-constructor
 */
class AgentRunReport extends ValueObject
{
    // The KNOWN stop reasons — the report OWNS this vocabulary (every string doubles as its translation key in
    // the app translation admin, so a wording tweak at a call site would silently drop the translation; call
    // sites reference the consts instead of re-typing the sentence).
    public const string STOP_REASON_REPEATED_STEP_WITHOUT_PROGRESS = 'I kept repeating the same step without making progress — I may be missing information I would need (you can rephrase or narrow the task down).';
    public const string STOP_REASON_COST_BUDGET_USED_UP = 'I reached the spending limit for one continuous stretch of work — a safety brake, nothing went wrong.';
    public const string STOP_REASON_STEP_BUDGET_USED_UP = 'I reached the limit for how many steps I may work in one go — a safety brake, nothing went wrong.';
    public const string STOP_REASON_RUN_ENDED_UNEXPECTEDLY = 'the run ended unexpectedly.';
    public const string STOP_REASON_NO_PROGRESS_ON_REQUEST = 'I could not make progress on this request.';
    /** Owner-facing sentences the inline terminals translate before the report names them ({@see AgentRunTerminalReport}). */
    public const string STOP_REASON_MONTHLY_AI_BUDGET_USED_UP = 'the monthly AI budget of your plan is used up — it resets at the start of next month.';
    public const string STOP_REASON_AI_SERVICE_ERROR = 'a technical problem stopped me (the AI service returned an error).';

    /** @var int Open items shown by TITLE in the report — beyond this only a "… and N more" count (a 25-item plan rendered as an unreadable wall). */
    public const int MAX_OPEN_ITEMS_IN_REPORT = 3;


    /** @var string WHY the run stopped, in plain words — one of the STOP_REASON_* constants (or a caller-derived line). */
    public string $stopReason = '';

    /**
     * @var string The customer's configured interface language — drives the `___()` translation of the report
     * SCAFFOLD (the item paths and statusNotes are already in the conversation's language, the model wrote
     * them; a hardcoded English frame would be delivered verbatim into non-English conversations). Keys = the
     * English source strings (app translation admin); a missing key degrades to the English key itself.
     * Stamped by {@see composeFromConversation}.
     */
    public string $languageCode = 'en';

    /** @var string[] Completed items as tree paths. */
    public array $doneItemPaths = [];

    /** @var string[] Still-open items as tree paths — the work the run did NOT finish. */
    public array $openItemPaths = [];

    /**
     * @var string[] The {@see TaskPlanItem::$ownerFacingTitle}s of the still-open leaves that carry one —
     * the ONLY item names the customer message quotes ("Next up: …"). Items without a title are counted
     * (via {@see $openItemPaths}) but never named.
     */
    public array $openItemOwnerFacingTitles = [];

    /** @var string[] Blocked items as already translated "title — reason" lines (call-to-action/retry wording included). */
    public array $blockedItemLines = [];

    /**
     * @var string[] Not-applicable items as translated "title — reason" lines — judged irrelevant to this space, NOT a
     * blocker.
     */
    public array $notApplicableItemLines = [];

    /** @var string|null The last persisted summary OUTCOME — enrichment only, never freshly generated. */
    public ?string $summaryOutcome = null;

    /**
     * @var string[] The owner-facing titles of what the cut turn SAVED ({@see AgentPausedTurnRecord::savedWriteToolNames()}
     *      through the tool's {@see MCPToolOwnerFacingSubject}) — the line the owner never got before: a turn cut by the
     *      step budget had saved several things, and the report counted items instead.
     */
    public array $savedTitles = [];

    /**
     * Compose the report from the conversation's PERSISTED state — pure assembly, no repo, no model call.
     * `$stopReason` comes from the terminal that stamps the obligation (the loop knows it directly; the sweep
     * derives it from the newest ERROR message or falls back to a generic line) and is already translated by
     * the caller.
     */
    public static function composeFromConversation(AIConversation $conversation, string $stopReason, ?AgentPausedTurnRecord $pausedTurnRecord = null): static
    {
        $agentRunReport = new static();
        $agentRunReport->stopReason = $stopReason;
        foreach ($pausedTurnRecord?->savedWriteToolNames() ?? [] as $savedWriteToolName) {
            $savedTitle = self::ownerFacingTitleForWriteTool($savedWriteToolName);
            if (!in_array($savedTitle, $agentRunReport->savedTitles, true)) {
                $agentRunReport->savedTitles[] = $savedTitle;
            }
        }
        $agentRunReport->languageCode = $conversation->resolveUserLanguageCode();
        // isset, never `->summary` — the summary lazy-load is an Argus read-through GENERATION.
        $summary = isset($conversation->summary) ? $conversation->summary : null;
        $agentRunReport->summaryOutcome = ($summary !== null && isset($summary->outcome)) ? $summary->outcome : null;

        $taskPlan = $conversation->getTaskPlan();
        if ($taskPlan?->items !== null) {
            $agentRunReport->collectItemPaths($taskPlan->items);
        }
        return $agentRunReport;
    }

    /** Sort every node of the plan into done/open/blocked report buckets (paths like "Bookings > Confirm slot"). */
    protected function collectItemPaths(TaskPlanItems $taskPlanItems): void
    {
        foreach ($taskPlanItems->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            // a blocked or not-applicable container prunes its subtree — its own line covers the children
            if ($taskPlanItemAddress->isInsideTerminalContainer) {
                continue;
            }
            $taskPlanItem = $taskPlanItemAddress->item;
            $itemPath = $taskPlanItemAddress->textPath;
            if ($taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED) {
                // Item names come EXCLUSIVELY from ownerFacingTitle (blueprint-maintained customer copy);
                // an item without one falls back to its statusNote alone — the model wrote the note short
                // and in the conversation's language. Never quote $text: it is agent instruction prose.
                $blockedItemTitle = $taskPlanItem->ownerFacingTitle !== null ? $this->translate($taskPlanItem->ownerFacingTitle) : null;
                // A note the platform wrote in fixed English is translated here like the fixed suffixes; the
                // model's own notes are already in the conversation's language.
                $fixedEnglishBlockedStatusNote = $taskPlanItem->getFixedEnglishBlockedStatusNote();
                $blockedStatusNote = $fixedEnglishBlockedStatusNote !== null
                    ? $this->translate($fixedEnglishBlockedStatusNote)
                    : (string)($taskPlanItem->statusNote ?? $this->translate('no reason given'));
                $this->blockedItemLines[] = $this->renderBlockedItemLine(
                    $blockedItemTitle,
                    $blockedStatusNote,
                    $taskPlanItem->requiresUserAction,
                    $taskPlanItem->retryable
                );
                continue; // blocked prunes its subtree — the named blocker covers the children
            }
            if ($taskPlanItem->status === TaskPlanItem::STATUS_NOT_APPLICABLE) {
                $notApplicableItemTitle = $taskPlanItem->ownerFacingTitle !== null ? $this->translate($taskPlanItem->ownerFacingTitle) : null;
                $notApplicableStatusNote = (string)($taskPlanItem->statusNote ?? $this->translate('not applicable'));
                // Without a title the bare note IS the line — model text or the already translated fallback.
                $this->notApplicableItemLines[] = $notApplicableItemTitle !== null
                    ? $this->translate('%title% — %note%', ['title' => $notApplicableItemTitle, 'note' => $notApplicableStatusNote])
                    : $notApplicableStatusNote;
                continue; // not-applicable prunes its subtree — the judgment covers the children
            }
            if ($taskPlanItem->isOpen()) {
                // an open container is named by its open descendants, which name the remainder precisely
                if ($taskPlanItemAddress->isLeaf) {
                    $this->openItemPaths[] = $itemPath;
                    if ($taskPlanItem->ownerFacingTitle !== null) {
                        $this->openItemOwnerFacingTitles[] = $taskPlanItem->ownerFacingTitle;
                    }
                }
                continue;
            }
            // A done PARENT is not counted: the progress line counts LEAVES only (a parent and its children counted
            // twice made "9 of 17" mean nothing to the owner); its children are walked and counted themselves.
            if (!$taskPlanItemAddress->isLeaf) {
                continue;
            }
            $statusNote = trim((string)($taskPlanItem->statusNote ?? ''));
            $this->doneItemPaths[] = $itemPath . ($statusNote !== '' ? ' — ' . $statusNote : '');
        }
    }

    /**
     * One blocked item as ONE translated line: the call-to-action / retry wording is part of the key, so each
     * language places it itself. Without a title and without a suffix the bare note is the line (no key: it is
     * model text or the already translated "no reason given").
     */
    protected function renderBlockedItemLine(?string $blockedItemTitle, string $blockedStatusNote, bool $requiresUserAction, bool $retryable): string
    {
        $linePlaceholders = ['title' => (string)$blockedItemTitle, 'note' => $blockedStatusNote];
        if ($blockedItemTitle !== null) {
            if ($requiresUserAction) {
                return $this->translate('%title% — %note% (needs your action to unblock)', $linePlaceholders);
            }
            if ($retryable) {
                return $this->translate('%title% — %note% (temporary problem — will be retried)', $linePlaceholders);
            }
            return $this->translate('%title% — %note%', $linePlaceholders);
        }
        if ($requiresUserAction) {
            return $this->translate('%note% (needs your action to unblock)', $linePlaceholders);
        }
        if ($retryable) {
            return $this->translate('%note% (temporary problem — will be retried)', $linePlaceholders);
        }
        return $blockedStatusNote;
    }

    /**
     * The owner-facing stop reason of a turn that ended because automated work is not permitted right now. The entity
     * next to the account is named with the application's word ({@see AgentApplicationWording::$billingEntityName}).
     */
    public static function buildStopReasonAutomationPaused(): string
    {
        return 'automated work is paused for your account or ' . AgentApplicationWording::current()->billingEntityName
            . ' — I continue once it is active again.';
    }

    /**
     * The user-facing report TEXT — the terminal chat message's content (replaces the generic fallback texts at
     * loop terminals; the sweep delivers the same text late). Plain sections, no markdown tables; empty buckets
     * are omitted so a plan-less run still yields an honest two-liner.
     */
    public function toChatMessageText(): string
    {
        $reportLines = [
            $this->translate('I paused here: %reason%', ['reason' => $this->translate($this->stopReason)]),
        ];
        // The customer WATCHED the completed work happen in the chat right above — a progress counter is honest
        // and readable; re-listing every internal task title plus its status note produced an unreadable insider
        // wall (every path of a seeded plan, like "Step 1 — Intake: …", dumped
        // into one paragraph).
        $doneCount = count($this->doneItemPaths);
        $totalCount = $doneCount + count($this->openItemPaths)
            + count($this->blockedItemLines) + count($this->notApplicableItemLines);
        if ($this->savedTitles !== []) {
            // The cut turn's landed writes, by subject — the owner was never told before the pause.
            $reportLines[] = '';
            $reportLines[] = $this->translate('Saved in this stretch:') . ' ' . implode(', ', array_map(fn (string $savedTitle): string => $this->translate($savedTitle), $this->savedTitles)) . '.';
        }
        if ($totalCount > 0) {
            $reportLines[] = '';
            $reportLines[] = $this->translatePlural(
                'Progress so far: %done% of %total% step is done.',
                'Progress so far: %done% of %total% steps are done.',
                $totalCount,
                ['done' => (string)$doneCount, 'total' => (string)$totalCount]
            );
        }
        // Only EXPLICIT customer titles are ever named ({@see TaskPlanItem::$ownerFacingTitle}, blueprint-
        // maintained English source strings run through the translation layer). Items without one — every
        // model-created item, and untitled seed items — stay in the progress counter only. This replaces the
        // former 60-char length heuristic, which let short internal titles leak verbatim.
        $shownOpenItemTitles = [];
        foreach (array_unique($this->openItemOwnerFacingTitles) as $openItemOwnerFacingTitle) {
            if (count($shownOpenItemTitles) >= self::MAX_OPEN_ITEMS_IN_REPORT) {
                break;
            }
            $shownOpenItemTitles[] = $this->translate($openItemOwnerFacingTitle);
        }
        if ($shownOpenItemTitles !== []) {
            $shownOpenItemsText = implode('; ', $shownOpenItemTitles);
            $remainingOpenCount = count($this->openItemPaths) - count($shownOpenItemTitles);
            $reportLines[] = '';
            $reportLines[] = $remainingOpenCount > 0
                ? $this->translatePlural(
                    'Next up: %items% … and %count% more.',
                    'Next up: %items% … and %count% more.',
                    $remainingOpenCount,
                    ['items' => $shownOpenItemsText, 'count' => (string)$remainingOpenCount]
                )
                : $this->translate('Next up: %items%.', ['items' => $shownOpenItemsText]);
        }
        if ($this->blockedItemLines !== []) {
            // blocked stays DETAILED — the reason + call-to-action is exactly what the customer must act on
            $reportLines[] = '';
            $reportLines[] = $this->translate('Blocked: %items%.', ['items' => implode(' | ', $this->blockedItemLines)]);
        }
        if ($this->notApplicableItemLines !== []) {
            $reportLines[] = '';
            $reportLines[] = $this->translate(
                AgentApplicationWording::current()->runReportNotApplicableItemsSentence,
                ['items' => implode(' | ', $this->notApplicableItemLines)]
            );
        }
        $reportLines[] = '';
        $reportLines[] = $this->translate('Just reply "continue" and I will pick up exactly where I stopped.');
        return implode("\n", $reportLines);
    }

    /**
     * The two-line pause FOOTER under a model-written closing message (the budget closing step): the progress line
     * and the continue invitation — what the eval's pause definition and the owner's "continue" key on. Appended by
     * the loop only when autonomous work remains; a closing message that ends on an owner question gets none.
     * `$invitesOwnerToContinue` false (an unattended scheduled run: nobody reads the chat, the text goes out by mail)
     * leaves the continue invitation out.
     */
    public function toPauseFooterText(bool $invitesOwnerToContinue = true): string
    {
        $doneCount = count($this->doneItemPaths);
        $totalCount = $doneCount + count($this->openItemPaths) + count($this->blockedItemLines) + count($this->notApplicableItemLines);
        $footerLines = [$this->translate('I paused here:') . ' ' . $this->translate($this->stopReason)];
        if ($totalCount > 0) {
            $footerLines[] = $this->translate(
                'Progress so far: %done% of %total% steps are done.',
                ['done' => (string)$doneCount, 'total' => (string)$totalCount]
            );
        }
        if ($invitesOwnerToContinue) {
            $footerLines[] = $this->translate('Just reply "continue" and I will pick up exactly where I stopped.');
        }
        return implode("\n", $footerLines);
    }

    /**
     * The owner-facing title of a write tool's subject, untranslated source string: the subject the tool declares
     * ({@see MCPToolOwnerFacingSubject}); a write tool without one is named by its verb-less camel-case words — readable,
     * never a raw tool name.
     */
    public static function ownerFacingTitleForWriteTool(string $toolName): string
    {
        $ownerFacingSubject = MCPToolAttributeCatalog::getAttributeOfToolName($toolName, MCPToolOwnerFacingSubject::class);
        if ($ownerFacingSubject !== null) {
            return $ownerFacingSubject->subject;
        }
        // "updateFooBar" → "Foo bar": the verb goes, the rest reads as words
        $words = preg_split('/(?=[A-Z])/', $toolName) ?: [$toolName];
        array_shift($words);
        $title = trim(strtolower(implode(' ', $words)));
        return $title === '' ? $toolName : ucfirst($title);
    }

    /**
     * One scaffold string through the app translation layer, in the report's language — key = the English
     * source string; a key missing in the translation store degrades to the English text itself. The known
     * stop-reason lines run through the same path, so their translations are admin-managed too. NOT free:
     * each key is a Redis get and, on a cache miss, one translation-store query — acceptable on the run-failure
     * path this report is composed on, but the reason the container-less unit tests exercise the degradation
     * branch.
     */
    protected function translate(string $englishSourceKey, array $placeholders = []): string
    {
        return $this->translateInContext($englishSourceKey, $englishSourceKey, Translatable::CONTEXT_ONE, $placeholders);
    }

    /**
     * A count-dependent line: ONE key (the singular English) whose plural wording lives in the key's MANY
     * value. The plural English is the degradation text for any count but 1, so a store without values still
     * renders correct English.
     */
    protected function translatePlural(string $singularEnglishKey, string $pluralEnglishWording, int $count, array $placeholders): string
    {
        if ($count === 1) {
            return $this->translateInContext($singularEnglishKey, $singularEnglishKey, Translatable::CONTEXT_ONE, $placeholders);
        }
        $pluralLine = $this->translateInContext($singularEnglishKey, $pluralEnglishWording, Translatable::CONTEXT_MANY, $placeholders);
        // translateKeyRC's cached "no values" branch returns the KEY and ignores defaultString — never serve
        // the English singular for a plural count.
        $englishSingularLine = Translatable::replacePlaceholders($singularEnglishKey, $placeholders);
        return $pluralLine === $englishSingularLine
            ? Translatable::replacePlaceholders($pluralEnglishWording, $placeholders)
            : $pluralLine;
    }

    /** The shared ___() call of {@see translate} and {@see translatePlural}, with the failure-path degradation. */
    protected function translateInContext(string $englishSourceKey, string $englishFallbackText, string $translationContext, array $placeholders): string
    {
        try {
            return ___(
                $englishSourceKey,
                context: $translationContext,
                languageCode: $this->languageCode,
                placeholders: $placeholders,
                defaultString: $englishFallbackText
            );
        } catch (Throwable $translationError) {
            // The report runs on the run's FAILURE path — an unreachable translation store must never take the
            // delivered report down with it; the English text (placeholders applied) is the honest degradation.
            // Logged in its own guard, so a permanently broken store is visible instead of silently English.
            try {
                DDDService::instance()->getLogger()->error(
                    'AgentRunReport: translation failed, degrading to English: ' . $translationError->getMessage()
                );
            } catch (Throwable) {
            }

            // The English source with its placeholders filled — a degraded report still says "3 of 5", never "%done%".
            $placeholderMap = [];
            foreach ($placeholders as $placeholderName => $placeholderValue) {
                $placeholderMap['%' . $placeholderName . '%'] = (string)$placeholderValue;
            }
            return $placeholderMap === [] ? $englishSourceKey : strtr($englishSourceKey, $placeholderMap);
        }
    }
}
