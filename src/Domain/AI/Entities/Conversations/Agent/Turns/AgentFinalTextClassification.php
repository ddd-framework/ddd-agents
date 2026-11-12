<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the checked stop reads about a candidate final text (formerly inline in
 * `runConversationLoop()`: one rule): whether the counter judges first, whether the decision model may
 * judge it, and whether the text hands the turn to the owner.
 *
 * @phpstan-consistent-constructor
 */
class AgentFinalTextClassification extends ValueObject
{
    /** An autonomous purpose: the deterministic counter judges first under every turn-closing mode. */
    public bool $counterFirstMandatory = false;

    /** A real final text (no tool call, no stall, no recorded owner question) outside the budget closing step. */
    public bool $decisionModelMayJudge = false;

    /** One of the last prose lines carries a sentence-ending `?` ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::textEndsWithOwnerQuestion()}). */
    public bool $finalTextAsksTheOwner = false;

    /** @var string[] The canonical types of the CLOSED, VALID widget blocks of the text */
    public array $closedTextBlockTypes = [];

    /**
     * @var string[] The widget types the turn's tools wrote (a confirmation widget, a form, a handover — the
     * turn's widget as much as one in the model's text,
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::findToolEmittedWidgetTypesInCurrentTurn()})
     */
    public array $toolEmittedWidgetTypesInTurn = [];

    /**
     * @var bool A widget of the text or of the turn's tools awaits the owner by its CONTENT, which its type cannot say —
     * a `ComposedView` with a `Form`.
     */
    public bool $widgetContentAwaitsOwnerInput = false;

    /**
     * The classification of one candidate final text.
     *
     * @param string[] $closedTextBlockTypes
     * @param string[] $toolEmittedWidgetTypesInTurn
     */
    public static function buildFor(
        bool $noToolCall,
        bool $isStall,
        bool $turnRecordedOwnerQuestion,
        bool $budgetClosingStep,
        bool $purposeWorksAutonomously,
        array $closedTextBlockTypes,
        array $toolEmittedWidgetTypesInTurn,
        bool $finalTextAsksTheOwner,
        bool $widgetContentAwaitsOwnerInput = false
    ): static {
        $finalTextClassification = new static();
        $finalTextClassification->widgetContentAwaitsOwnerInput = $widgetContentAwaitsOwnerInput;
        $finalTextClassification->closedTextBlockTypes = $closedTextBlockTypes;
        $finalTextClassification->toolEmittedWidgetTypesInTurn = $toolEmittedWidgetTypesInTurn;
        $finalTextClassification->counterFirstMandatory = $purposeWorksAutonomously;
        $finalTextClassification->decisionModelMayJudge = $noToolCall && !$isStall && !$turnRecordedOwnerQuestion && !$budgetClosingStep;
        $finalTextClassification->finalTextAsksTheOwner = $finalTextAsksTheOwner;
        return $finalTextClassification;
    }

    /**
     * Whether the text hands the turn to the owner: it asks, or a widget of the turn's tools or of the text awaits the
     * owner's input or ends the purpose (asked in that order).
     */
    public function handsTheTurnToTheOwner(AIConversation $conversation): bool
    {
        return $this->finalTextAsksTheOwner
            || $this->widgetContentAwaitsOwnerInput
            || $conversation->hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose($this->toolEmittedWidgetTypesInTurn)
            || $conversation->hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose($this->closedTextBlockTypes);
    }
}
