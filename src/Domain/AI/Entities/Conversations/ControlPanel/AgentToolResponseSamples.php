<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tool-response drill-down set (the per-tool view of an application's debug console command): one
 * {@see AgentToolResponseSample} per tool-call occurrence of ONE tool over a range, largest result first. Built by
 * `DBAIConversationMessage::findLargestToolResponseSamples()`.
 *
 * @property AgentToolResponseSample[] $elements
 * @method AgentToolResponseSample[] getElements()
 * @method AgentToolResponseSample|null first()
 * @method AgentToolResponseSample getByUniqueKey(string $uniqueKey)
 */
class AgentToolResponseSamples extends ObjectSet
{
}
