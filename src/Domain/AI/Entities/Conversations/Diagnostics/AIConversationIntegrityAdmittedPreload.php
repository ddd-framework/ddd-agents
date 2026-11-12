<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One skill a preload decision admitted at the start of a turn, as the integrity report found it in the stored rows
 * ({@see AIConversationIntegrityReport::$admittedPreloads}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationIntegrityAdmittedPreload extends ValueObject
{
    /** @var int The index of the message that started the turn */
    public int $initiatorMessageIndex = 0;

    /** @var string The admitted skill */
    public string $skillName = '';

    /** @var bool A row from the turn's initiator up to its first model output loads the skill */
    public bool $onAnchor = false;

    /** @var bool The model called `load_skills` for the skill again in the same turn */
    public bool $reloaded = false;

    /**
     * @param int $initiatorMessageIndex
     * @param string $skillName
     * @param bool $onAnchor
     * @param bool $reloaded
     * @return static
     */
    public static function forSkillOfTurn(int $initiatorMessageIndex, string $skillName, bool $onAnchor, bool $reloaded): static
    {
        $admittedPreload = new static();
        $admittedPreload->initiatorMessageIndex = $initiatorMessageIndex;
        $admittedPreload->skillName = $skillName;
        $admittedPreload->onAnchor = $onAnchor;
        $admittedPreload->reloaded = $reloaded;
        return $admittedPreload;
    }
}
