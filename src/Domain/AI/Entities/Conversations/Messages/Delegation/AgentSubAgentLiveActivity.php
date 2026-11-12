<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What a running sub-agent does right now: its child's run state, heartbeat, the number of tool-call
 * steps of the current run and the newest steps — read live for `check_delegations`, never stored.
 *
 * @phpstan-consistent-constructor
 */
class AgentSubAgentLiveActivity extends ValueObject
{
    /** The child conversation. */
    public int $childConversationId = 0;

    /** The child's run state. */
    public string $runState = '';

    public ?DateTime $runHeartbeatAt = null;

    /** The tool-call steps of the current run. */
    public int $stepCount = 0;

    /** @var AgentSubAgentLiveStep[] the newest steps, newest first */
    public array $newestSteps = [];

    /** @param AgentSubAgentLiveStep[] $newestSteps newest first */
    public static function forChild(int $childConversationId, string $runState, ?DateTime $runHeartbeatAt, int $stepCount, array $newestSteps): static
    {
        $agentSubAgentLiveActivity = new static();
        $agentSubAgentLiveActivity->childConversationId = $childConversationId;
        $agentSubAgentLiveActivity->runState = $runState;
        $agentSubAgentLiveActivity->runHeartbeatAt = $runHeartbeatAt;
        $agentSubAgentLiveActivity->stepCount = $stepCount;
        $agentSubAgentLiveActivity->newestSteps = $newestSteps;
        return $agentSubAgentLiveActivity;
    }

    /** ` — 9 steps, last step 20 s ago: getWebPageAsMarkdown` (only the count without a step). */
    public function buildActivitySuffix(DateTime $now): string
    {
        $newestStep = $this->newestSteps[0] ?? null;
        $stepCountText = $this->stepCount . ($this->stepCount === 1 ? ' step' : ' steps');
        if ($newestStep === null) {
            return ' — ' . $stepCountText;
        }
        $lastStepAge = $newestStep->createdAt === null
            ? ''
            : ' ' . DelegationSubAgentRun::formatDuration(max(0, $now->getTimestamp() - $newestStep->createdAt->getTimestamp())) . ' ago';
        return ' — ' . $stepCountText . ', last step' . $lastStepAge . ': ' . $newestStep->toolName;
    }
}
