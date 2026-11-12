<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Whether one model response of the loop is a STALL, and of which shape (formerly
 * inline in `runConversationLoop()`). Stall recovery is signal-first and universal; a response that produced
 * NO tool call is a stall iff —
 *  - EMPTY: it emitted neither a tool call nor visible prose — ALWAYS a stall (the model must produce something; even
 *    with only meta-tools it should at least call load_skills or answer). A fenced block (a ```widget``` the frontend
 *    renders as a component) is not visible prose: a response whose only content is such a block renders as an EMPTY
 *    message to the owner, so it is signalled, not accepted as the final answer;
 *  - PROSE-AFTER-LOAD-SKILLS: it answered in prose on a turn where it JUST loaded a skill (signalled the intent to do
 *    data work, then did not use the tool), no work tool has run yet this turn and work tools are offered. A prose answer
 *    with skills CARRIED FORWARD from an earlier turn is a legitimate conversational reply
 *    ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::hasSkillOrToolLoadCall()}) — never
 *    recovered (the discriminator that stops "every model calls a tool the moment skills are loaded");
 *  - PRE-CALL: the model announced an imminent action with the {preCallMessage} sentinel (a deterministic protocol
 *    token, never a natural-language guess) or leaked a mangled tool call into its prose, and made NO tool
 *    call — the stall path discards the leaked text and nudges / forces a REAL tool call;
 *  - DICTATED-BLOCK-MISSING: it answered in prose with no widget block although the turn's only work-tool result
 *    dictates one without a condition (a typed platform fact on the result, {@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$dictatedWidgetType},
 *    never a match on the note's wording) — the intro line alone, or reasoning that leaked in place of the answer. The
 *    recovery discards the text and nudges once to write the line and the block; never in a delegation (its text is a
 *    verdict to the parent), never under the one-time prose exemption (the budget closing step, the answer-now move),
 *    at most once per owner turn. The same shape fires when the turn's newest tool call is a `delegate` that started
 *    nothing (FAILED or refused, {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::findNewestToolCallWhenADelegateThatStartedNothingWithoutNudgeSince()})
 *    and the answer has no block: the model meant to hand the work off and wrote only a line — its own nudge text
 *    ({@see $followsADelegateThatStartedNothing});
 *  - DIRECT-WIDGET-MISSING: the turn start decided the turn's DIRECT widget (a `Clock`, a `WeatherForecast`, a
 *    `WritingDraft`, a `ComposedView` of a layout the owner named — {@see AIConversation::getDecidedDirectWidgetTypeOfCurrentTurn()},
 *    a typed fact, never a match on the owner's words), no work tool ran this turn, no `delegate` call was made, and the
 *    answer carries visible text but no closed, valid block of that type — the line alone (the
 *    same input gave the line plus the block or the line alone). The recovery discards the text and nudges once to
 *    write the line and the block (or to ask the owner with `askOwner`; an unclosed or malformed attempt gets its own
 *    text); never for a sub-agent, never under the one-time prose exemption, never under a widget that awaits the
 *    owner or for a text that ends on a question to the owner, only while the turn carries no LOOP_NUDGE yet, at most
 *    once per owner turn;
 *  - WIDGET-TREE-FINDINGS: the answer's closed, valid `ComposedView` block reads with findings that DROP a node or a
 *    bound value (an unknown node, a missing required prop, a node in a slot that does not take it, a binding to
 *    nothing — {@see WidgetBlocks::buildComposedViewFindingLinesThatDropANodeOrABinding()}): the block still counts as
 *    valid and renders what was kept, and the frontend's render report would come only after the turn
 *    (the form, map and repeat cases). The recovery holds the text and sends the finding lines as one
 *    `WIDGET_TREE_FINDINGS` signal, asking for the corrected block in the next response — once per owner turn, only
 *    while the turn carries no LOOP_NUDGE yet and the trailing nudges leave room for the signal, never for a sub-agent,
 *    never under the one-time prose exemption; a finding that keeps the node never fires it;
 *  - CODE-OR-FORMULA-IN-PROSE: the answer carries a fenced code block that is not a widget fence, or a LaTeX `\[` / `\(`
 *    in its prose ({@see AgentFinalTextMarkdownReading} — a Markdown parse, never a pattern over the text), on a
 *    conversation taught the kit ({@see AIConversation::isTaughtTheComposedViewKit()}): a formula or a code sample is a
 *    `Math` / `Code` node of a `ComposedView` (taught, yet ignored at low
 *    effort). Also when the fence is the whole answer (the EMPTY shape gives way to it). One nudge, only while the turn
 *    carries no LOOP_NUDGE yet; never for a sub-agent, under the one-time prose exemption, after an owner question or
 *    under a widget that awaits the owner.
 *
 * DIRECT-WIDGET-MISSING, WIDGET-TREE-FINDINGS and CODE-OR-FORMULA-IN-PROSE are corrections of the answer's shape: each
 * fires only while the turn carries neither a LOOP_NUDGE nor a WIDGET_TREE_FINDINGS signal, so one owner turn discards
 * at most one answer between them.
 *
 * A turn that recorded an `askOwner` question is a COMPLETE turn output: nudging it re-drove the model into a search
 * with a name the owner had not chosen yet. The former `OwnerActionConfirm` consent block exempts
 * nothing: no tool asks for it (the consent is read from the owner's own words inside the tool), so
 * an answer that only shows that block and calls no tool is judged like any other answer.
 * The two prose predicates read the turn's OWN widget: a widget of the text or of the turn's
 * tool and platform rows that awaits the owner's input or ends the purpose — that scan parses the turn's rows and is
 * asked only where a stall predicate can fire ({@see needsTurnWidgetScan()}). A leaked call keeps only the exemption of
 * a recorded owner question: settled as the final text under any other widget, it would show the owner raw call syntax.
 * A response whose text carries a CLOSED, VALID block of a widget the model writes without a skill
 * ({@see WidgetBlocks::hasClosedBlockWritableWithoutASkill()} — `WeatherForecast`, `Clock`, `WritingDraft`,
 * `ComposedView`, `CustomVisualization`, …) is a delivered answer: never a PROSE-AFTER-LOAD-SKILLS stall, even when a
 * preloaded skill's tools were not needed, and never an EMPTY stall either — the EMPTY shape strips every fenced block
 * before it looks for prose, so a draft written as a `WritingDraft` block alone read as
 * "no output". A MALFORMED block exempts nothing; the PRE-CALL shape is unchanged.
 *
 * `$workToolRan` is the caller's "a work tool was called this turn": a call the platform REFUSED (a read-only run, a
 * missing mandate) or that FAILED counts — the model did use the skill, and a prose answer about the refusal is no
 * prose-after-load-skills stall ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::hasWorkToolCallThatRanOrFailed()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentStallClassification extends ValueObject
{
    /** The response carries no tool call. */
    public bool $noToolCall = false;

    /** No tool call AND no visible prose ({@see \DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse::emitsNoOutput()}). */
    public bool $emittedNoText = false;

    /** The turn recorded an `askOwner` question. */
    public bool $turnRecordedOwnerQuestion = false;

    /** The value above, or the turn's own widget awaits the owner's input or ends the purpose (scanned only when it can matter). */
    public bool $turnWidgetAwaitsTheOwnerOrEndsThePurpose = false;

    /**
     * The response text carries a closed, valid block of a widget written without a skill
     * ({@see WidgetBlocks::hasClosedBlockWritableWithoutASkill()}); read only when the prose predicate could fire or the
     * response shows no prose (the EMPTY shape).
     */
    public bool $responseCarriesWidgetWritableWithoutASkill = false;

    public bool $isProseStallAfterLoadSkills = false;

    public bool $isPreCallStall = false;

    public bool $isDictatedBlockMissingStall = false;

    /** The tool whose result dictates the missing block (set with {@see $isDictatedBlockMissingStall}). */
    public ?string $dictatingToolName = null;

    /** The widget type that result dictates (set with {@see $isDictatedBlockMissingStall}). */
    public ?string $dictatedWidgetType = null;

    /** The DICTATED-BLOCK-MISSING stall follows a `delegate` call that started nothing, not a dictating result. */
    public bool $followsADelegateThatStartedNothing = false;

    public bool $isDirectWidgetMissingStall = false;

    /** The turn's decided direct widget the answer lacks (set with {@see $isDirectWidgetMissingStall}). */
    public ?string $directWidgetType = null;

    /**
     * The answer attempted the decided block but it renders nothing: a block of that type that is not closed, or a
     * malformed block (set with {@see $isDirectWidgetMissingStall}; its own nudge text).
     */
    public bool $directWidgetBlockIsUnclosedOrMalformed = false;

    public bool $isWidgetTreeFindingsStall = false;

    /** @var string[] The answer's `ComposedView` findings that drop something, one line each (set with {@see $isWidgetTreeFindingsStall}). */
    public array $widgetTreeFindingLines = [];

    public bool $isCodeOrFormulaInProseStall = false;

    public bool $isStall = false;

    /**
     * The classification of one response. The facts are the response's shape and the turn's state; the turn's own
     * widget and its newest dictating work result are asked of `$conversation` only when they can change a predicate.
     * `$dictatedBlockNudgeSpentThisTurn`: the loop already nudged a DICTATED-BLOCK-MISSING stall in this owner turn;
     * `$directWidgetNudgeSpentThisTurn` the same for DIRECT-WIDGET-MISSING, `$widgetTreeFindingsSpentThisTurn` for
     * WIDGET-TREE-FINDINGS, `$codeOrFormulaNudgeSpentThisTurn` for CODE-OR-FORMULA-IN-PROSE.
     */
    public static function buildFor(
        AIConversation $conversation,
        string $responseText,
        bool $noToolCall,
        bool $emittedNoText,
        bool $turnRecordedOwnerQuestion,
        bool $workToolRan,
        bool $workToolsOffered,
        bool $proseStallExemptionApplies,
        bool $turnHasSkillOrToolLoadCall,
        bool $visibleProseCarriesLeakedToolCall,
        bool $visibleProseCarriesPreCallToken,
        bool $dictatedBlockNudgeSpentThisTurn = false,
        bool $directWidgetNudgeSpentThisTurn = false,
        bool $widgetTreeFindingsSpentThisTurn = false,
        bool $codeOrFormulaNudgeSpentThisTurn = false
    ): static {
        $stallClassification = new static();
        $stallClassification->noToolCall = $noToolCall;
        $stallClassification->emittedNoText = $emittedNoText;
        $stallClassification->turnRecordedOwnerQuestion = $turnRecordedOwnerQuestion;
        $stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose = $turnRecordedOwnerQuestion
            || (self::needsTurnWidgetScan($noToolCall, $emittedNoText, $turnRecordedOwnerQuestion)
                && $conversation->hasCurrentTurnWidgetAwaitingTheOwnerOrEndingThePurpose($responseText));
        $proseStallPreconditionsHold = $noToolCall
            && !$emittedNoText
            && !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose
            && !$workToolRan
            && $workToolsOffered
            && !$proseStallExemptionApplies
            && $turnHasSkillOrToolLoadCall;
        $stallClassification->responseCarriesWidgetWritableWithoutASkill = ($proseStallPreconditionsHold || $emittedNoText)
            && WidgetBlocks::fromMarkdown($responseText)->hasClosedBlockWritableWithoutASkill();
        $stallClassification->isProseStallAfterLoadSkills = $proseStallPreconditionsHold
            && !$stallClassification->responseCarriesWidgetWritableWithoutASkill;
        $isEmptyStall = $emittedNoText && !$stallClassification->responseCarriesWidgetWritableWithoutASkill;
        $stallClassification->isPreCallStall = $noToolCall
            && !$emittedNoText
            && ($visibleProseCarriesLeakedToolCall
                ? !$turnRecordedOwnerQuestion
                : !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose)
            && $visibleProseCarriesPreCallToken;
        $dictatedBlockPreconditionsHold = $noToolCall
            && !$emittedNoText
            && !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose
            && !$proseStallExemptionApplies
            && !$dictatedBlockNudgeSpentThisTurn
            && !$stallClassification->isProseStallAfterLoadSkills
            && !$stallClassification->isPreCallStall
            && !$conversation->isDelegation()
            && WidgetBlocks::fromMarkdown($responseText)->count() === 0;
        $currentTurn = $dictatedBlockPreconditionsHold ? $conversation->messages->buildCurrentTurn() : null;
        $delegateCallThatStartedNothing = $currentTurn?->findNewestToolCallWhenADelegateThatStartedNothingWithoutNudgeSince();
        $dictatingToolCall = $delegateCallThatStartedNothing === null ? $currentTurn?->findNewestWorkToolCallDictatingAWidgetWithoutNudgeSince() : null;
        if ($delegateCallThatStartedNothing !== null) {
            $stallClassification->isDictatedBlockMissingStall = true;
            $stallClassification->followsADelegateThatStartedNothing = true;
            $stallClassification->dictatingToolName = $delegateCallThatStartedNothing->name;
        } elseif ($dictatingToolCall !== null) {
            $stallClassification->isDictatedBlockMissingStall = true;
            $stallClassification->dictatingToolName = $dictatingToolCall->name;
            $stallClassification->dictatedWidgetType = $dictatingToolCall->dictatedWidgetType;
        }
        $stallClassification->directWidgetType = self::findMissingDirectWidgetType(
            $conversation,
            $responseText,
            $noToolCall
                && !$emittedNoText
                && !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose
                && !$workToolRan
                && !$proseStallExemptionApplies
                && !$directWidgetNudgeSpentThisTurn
                && !$stallClassification->isProseStallAfterLoadSkills
                && !$stallClassification->isPreCallStall
                && !$stallClassification->isDictatedBlockMissingStall
                && !$conversation->isDelegation()
        );
        $stallClassification->isDirectWidgetMissingStall = $stallClassification->directWidgetType !== null;
        $stallClassification->directWidgetBlockIsUnclosedOrMalformed = $stallClassification->isDirectWidgetMissingStall
            && WidgetBlocks::fromMarkdown($responseText)->hasUnclosedBlockOfTypeOrMalformedBlock((string)$stallClassification->directWidgetType);
        $stallClassification->widgetTreeFindingLines = $noToolCall
            && !$isEmptyStall
            && !$proseStallExemptionApplies
            && !$widgetTreeFindingsSpentThisTurn
            && !$stallClassification->isProseStallAfterLoadSkills
            && !$stallClassification->isPreCallStall
            && !$stallClassification->isDictatedBlockMissingStall
            && !$stallClassification->isDirectWidgetMissingStall
            && !$conversation->isDelegation()
                ? self::findWidgetTreeFindingLinesNotYetSignalled($conversation, $responseText)
                : [];
        $stallClassification->isWidgetTreeFindingsStall = $stallClassification->widgetTreeFindingLines !== [];
        $stallClassification->isCodeOrFormulaInProseStall = $noToolCall
            && trim($responseText) !== ''
            && !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose
            && !$proseStallExemptionApplies
            && !$codeOrFormulaNudgeSpentThisTurn
            && !$stallClassification->isProseStallAfterLoadSkills
            && !$stallClassification->isPreCallStall
            && !$stallClassification->isDictatedBlockMissingStall
            && !$stallClassification->isDirectWidgetMissingStall
            && !$stallClassification->isWidgetTreeFindingsStall
            && self::carriesCodeOrFormulaOnATaughtSurface($conversation, $responseText);
        $stallClassification->isStall = $isEmptyStall || $stallClassification->isProseStallAfterLoadSkills || $stallClassification->isPreCallStall
            || $stallClassification->isDictatedBlockMissingStall || $stallClassification->isDirectWidgetMissingStall
            || $stallClassification->isWidgetTreeFindingsStall || $stallClassification->isCodeOrFormulaInProseStall;
        return $stallClassification;
    }

    /**
     * The turn's decided direct widget when `$preconditionsHold` and the answer lacks its closed, valid block, does not
     * end on a question to the owner (the model asks for what only the owner can give — "which tone should the e-mail
     * have?"), the turn made no `delegate` call and carries no LOOP_NUDGE yet — null otherwise (the
     * DIRECT-WIDGET-MISSING shape).
     */
    protected static function findMissingDirectWidgetType(AIConversation $conversation, string $responseText, bool $preconditionsHold): ?string
    {
        $directWidgetType = $preconditionsHold ? $conversation->getDecidedDirectWidgetTypeOfCurrentTurn() : null;
        if ($directWidgetType === null || WidgetBlocks::fromMarkdown($responseText)->hasClosedValidBlockOfType($directWidgetType)
            || AIConversationMessages::textEndsWithOwnerQuestion($responseText)
        ) {
            return null;
        }
        $currentTurn = $conversation->messages->buildCurrentTurn();
        return $currentTurn->countToolCallsNamed(DelegateTool::NAME) === 0 && self::turnCarriesNoAnswerShapeCorrection($currentTurn) ? $directWidgetType : null;
    }

    /**
     * Whether the turn carries no answer-shape correction yet — no LOOP_NUDGE and no WIDGET_TREE_FINDINGS signal: the
     * DIRECT-WIDGET-MISSING, WIDGET-TREE-FINDINGS and CODE-OR-FORMULA-IN-PROSE shapes discard at most ONE answer per
     * owner turn between them.
     */
    protected static function turnCarriesNoAnswerShapeCorrection(AIConversationTurnMessages $currentTurn): bool
    {
        return $currentTurn->countLoopNudges() === 0 && !$currentTurn->hasSignalOfSource(AIConversationMessage::SIGNAL_SOURCE_WIDGET_TREE_FINDINGS);
    }

    /**
     * The answer's `ComposedView` finding lines that drop something, while the turn carries no answer-shape correction
     * yet (neither a WIDGET_TREE_FINDINGS signal — a resumed chunk — nor a LOOP_NUDGE) and the trailing nudges leave room
     * for the signal ({@see AgentStallRecoveryService::MAX_STALL_SIGNALS}: past it the recovery sends none, and a held
     * text would be accepted as a stall); [] otherwise.
     *
     * @return string[]
     */
    protected static function findWidgetTreeFindingLinesNotYetSignalled(AIConversation $conversation, string $responseText): array
    {
        if (!self::turnCarriesNoAnswerShapeCorrection($conversation->messages->buildCurrentTurn())
            || $conversation->messages->countTrailingConsecutiveNudges() >= AgentStallRecoveryService::MAX_STALL_SIGNALS
        ) {
            return [];
        }
        return WidgetBlocks::fromMarkdown($responseText)->buildComposedViewFindingLinesThatDropANodeOrABinding();
    }

    /**
     * Whether the text carries a code fence or a LaTeX formula ({@see AgentFinalTextMarkdownReading}) on a root
     * conversation taught the kit, while the turn carries no answer-shape correction yet — the turn and the surface
     * first, the parse only then.
     */
    protected static function carriesCodeOrFormulaOnATaughtSurface(AIConversation $conversation, string $responseText): bool
    {
        return self::turnCarriesNoAnswerShapeCorrection($conversation->messages->buildCurrentTurn())
            && $conversation->isTaughtTheComposedViewKit()
            && AgentFinalTextMarkdownReading::fromFinalText($responseText)->carriesCodeFenceOrLatexFormula();
    }

    /** Whether the turn's own widget can change a stall predicate: no tool call, visible text, no recorded owner question. */
    public static function needsTurnWidgetScan(bool $noToolCall, bool $emittedNoText, bool $turnRecordedOwnerQuestion): bool
    {
        return !$turnRecordedOwnerQuestion && $noToolCall && !$emittedNoText;
    }

    /** The {@see AgentStallRecoveryDecision} `SHAPE_*` of a stall; null when the response is no stall. */
    public function getStallShape(): ?string
    {
        if (!$this->isStall) {
            return null;
        }
        if ($this->isCodeOrFormulaInProseStall) {
            return AgentStallRecoveryDecision::SHAPE_CODE_OR_FORMULA_IN_PROSE;
        }
        if ($this->emittedNoText) {
            return AgentStallRecoveryDecision::SHAPE_EMPTY;
        }
        if ($this->isPreCallStall) {
            return AgentStallRecoveryDecision::SHAPE_PRE_CALL_ANNOUNCE;
        }
        if ($this->isDictatedBlockMissingStall) {
            return AgentStallRecoveryDecision::SHAPE_DICTATED_BLOCK_MISSING;
        }
        if ($this->isDirectWidgetMissingStall) {
            return AgentStallRecoveryDecision::SHAPE_DIRECT_WIDGET_MISSING;
        }
        return $this->isWidgetTreeFindingsStall ? AgentStallRecoveryDecision::SHAPE_WIDGET_TREE_FINDINGS : AgentStallRecoveryDecision::SHAPE_PROSE_AFTER_LOAD_SKILLS;
    }
}
