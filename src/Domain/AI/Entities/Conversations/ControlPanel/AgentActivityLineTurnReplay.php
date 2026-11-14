<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One turn of a conversation replayed through the activity narrator (the activity-lines console command): the turn
 * and its narrated tool calls. Identified by the turn ({@see AgentActivityLineTurnReplays}).
 *
 * @phpstan-consistent-constructor
 */
class AgentActivityLineTurnReplay extends ValueObject
{
    /** @var int The `indexInConversation` of the message that starts the turn */
    public int $turnIndex = 0;

    /** @var AgentActivityLineElementReplays The narrated tool calls of the turn */
    public AgentActivityLineElementReplays $elementReplays;

    public function __construct()
    {
        parent::__construct();
        $this->elementReplays = new AgentActivityLineElementReplays();
    }

    /**
     * @param int $turnIndex
     * @return static a turn without narrated tool calls yet
     */
    public static function forTurnIndex(int $turnIndex): static
    {
        $agentActivityLineTurnReplay = new static();
        $agentActivityLineTurnReplay->turnIndex = $turnIndex;
        return $agentActivityLineTurnReplay;
    }

    /**
     * @param int $turnIndex
     * @return string the key of the turn in an {@see AgentActivityLineTurnReplays}
     */
    public static function uniqueKeyForTurnIndex(int $turnIndex): string
    {
        return self::class . '_' . $turnIndex;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForTurnIndex($this->turnIndex);
    }
}
