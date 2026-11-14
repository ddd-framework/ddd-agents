<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The narrated tool calls of one replayed turn ({@see AgentActivityLineTurnReplay::$elementReplays}), one per
 * tool-call message, in the order the narrator first saw them.
 *
 * @property AgentActivityLineElementReplay[] $elements
 * @method AgentActivityLineElementReplay[] getElements()
 * @method AgentActivityLineElementReplay|null first()
 * @method AgentActivityLineElementReplay|null getByUniqueKey(string $uniqueKey)
 */
class AgentActivityLineElementReplays extends ObjectSet
{
    /**
     * @param int $toolCallMessageId
     * @return AgentActivityLineElementReplay|null the element of that tool-call message
     */
    public function getByToolCallMessageId(int $toolCallMessageId): ?AgentActivityLineElementReplay
    {
        return $this->getByUniqueKey(AgentActivityLineElementReplay::uniqueKeyForToolCallMessageId($toolCallMessageId));
    }

    /**
     * The element of the activity line's tool call; the first activity line of a call adds it, described by that line.
     *
     * @param AgentActivityLine $activityLine
     * @return AgentActivityLineElementReplay
     */
    public function findOrAddForActivityLine(AgentActivityLine $activityLine): AgentActivityLineElementReplay
    {
        $existingElementReplay = $this->getByToolCallMessageId($activityLine->toolCallMessageId);
        if ($existingElementReplay !== null) {
            return $existingElementReplay;
        }
        $addedElementReplay = AgentActivityLineElementReplay::forActivityLine($activityLine);
        // the set takes its elements by reference: it is handed a variable of its own
        $elementReplayToAdd = $addedElementReplay;
        $this->add($elementReplayToAdd);
        return $addedElementReplay;
    }
}
