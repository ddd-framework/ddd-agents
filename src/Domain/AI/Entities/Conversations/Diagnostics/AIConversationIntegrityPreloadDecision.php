<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One stored skill-preload decision of a conversation, reduced to what the integrity checks read
 * ({@see AIConversationIntegrityRecords::$preloadDecisions}): the turn it was taken in, its pass, whether it was
 * applied, the skills it admitted and why it was skipped.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationIntegrityPreloadDecision extends ValueObject
{
    /** @var int The index of the message that started the turn the decision was taken in */
    public int $initiatorMessageIndex = 0;

    /** @var int|null The decision's pass within the turn; null when the stored decision carries none */
    public ?int $pass = null;

    /** @var bool The decision's skills were admitted to the turn */
    public bool $applied = false;

    /** @var string[] The skills the decision admitted */
    public array $admittedSkillNames = [];

    /** @var string|null Why the decision admitted nothing, one of the `AgentSkillPreloadDecision::SKIP_*` constants; null when it was not skipped */
    public ?string $skipReason = null;

    /**
     * @param int $initiatorMessageIndex
     * @param int|null $pass
     * @param bool $applied
     * @param string[] $admittedSkillNames
     * @param string|null $skipReason
     * @return static
     */
    public static function forTurn(int $initiatorMessageIndex, ?int $pass, bool $applied, array $admittedSkillNames, ?string $skipReason = null): static
    {
        $preloadDecision = new static();
        $preloadDecision->initiatorMessageIndex = $initiatorMessageIndex;
        $preloadDecision->pass = $pass;
        $preloadDecision->applied = $applied;
        $preloadDecision->admittedSkillNames = $admittedSkillNames;
        $preloadDecision->skipReason = $skipReason;
        return $preloadDecision;
    }
}
