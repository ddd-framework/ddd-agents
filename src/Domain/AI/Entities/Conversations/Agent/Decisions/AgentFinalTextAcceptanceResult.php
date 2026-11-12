<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the checked-stop resolver decided for ONE final text: bounce or accept, which guard produced it
 * (`deterministic` / `decision-model`), the path taken (for the trace) and the fallback reason when the decision model could not
 * answer under `decisionModel`. {@see signalSource()} is the tag the bounce signal carries.
 */
class AgentFinalTextAcceptanceResult extends ValueObject
{
    public const string TRIGGER_DETERMINISTIC_COUNTER = 'deterministicCounter';
    public const string TRIGGER_DECISION_MODEL = 'decision-model';

    public const string RULE_DETERMINISTIC_COUNTER_FIRST = 'deterministicCounter';
    public const string RULE_COUNTER_FIRST_MANDATORY = 'counterFirstMandatory';
    public const string RULE_ONE_BOUNCE_PER_TURN_REACHED = 'capOne';
    public const string RULE_TEXT_NOT_JUDGEABLE = 'notAskable';
    public const string RULE_DECISION_MODEL = 'jev';
    public const string RULE_COUNTER_FALLBACK_PREFIX = 'fallback:';

    /** Mode `counterThenDecisionModel`: the counter accepted, the decision model was not asked. */
    public const string RULE_COUNTER_SILENT = 'counterSilent';
    /** The counter wanted to bounce, the decision model said the text does not announce — accepted. */
    public const string RULE_DECISION_MODEL_VETOED_COUNTER = 'jevVetoedCounter';
    /** The counter wanted to bounce and the decision model agreed — bounced with today's tag. */
    public const string RULE_DECISION_MODEL_CONFIRMED_COUNTER = 'jevConfirmedCounter';

    public bool $bounce = false;

    /** @var string|null TRIGGER_* when {@see $bounce}; null otherwise. */
    public ?string $trigger = null;

    public string $turnClosingMode = AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;

    public string $decidingRule = self::RULE_DETERMINISTIC_COUNTER_FIRST;

    /** @var string|null The AgentTurnClosingOutcome::REASON_* the decision model fell back on under `decisionModel` / `counterThenDecisionModel`; null otherwise. */
    public ?string $fallbackReason = null;

    /** @var AgentTurnClosingOutcome|null The decision outcome, when the decision model was asked. */
    public ?AgentTurnClosingOutcome $outcome = null;

    /**
     * The bounce signal's tag: the second source ONLY for a decision-model-caused bounce under `decisionModel` outside the kept-as-today
     * rows (the cap-1 rule counts exactly these); every other bounce keeps today's tag, so the wire under `deterministicCounter`
     * never changes.
     */
    public function signalSource(): string
    {
        return $this->trigger === self::TRIGGER_DECISION_MODEL && $this->decidingRule === self::RULE_DECISION_MODEL
            ? AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE_DECISION
            : AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE;
    }
}
