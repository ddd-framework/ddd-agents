<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;

/**
 * The activity narrator with scripted elements per turn and a narrator that answers without a model call: every
 * pending element gets the line `Line for #<id> (<state>)`, except a tool named {@see self::REFUSED_TOOL_NAME}, whose
 * answer is not JSON. Each batch adds fixed figures (per element 100 input and 10 output tokens, 0.125 USD; 0.25 s per
 * batch).
 */
class ScriptedActivityLinesNarratorServiceDouble extends AgentActivityLinesService
{
    /** @var string The tool whose element the narrator answers with text that is not JSON */
    public const string REFUSED_TOOL_NAME = 'refusedByNarrator';

    /** @var AgentActivityLine[][] The elements per turn index */
    public array $scriptedElementsByTurnIndex = [];

    /** @var string[] One entry per narrate call: turn, model, effort and the count of pending elements */
    public array $narrateCalls = [];

    public function buildElementsForTurn(AIConversation $conversation, ?int $turnInitiatorIndex = null): AgentActivityLines
    {
        $activityLines = new AgentActivityLines();
        foreach ($this->scriptedElementsByTurnIndex[(int)$turnInitiatorIndex] ?? [] as $scriptedActivityLine) {
            $activityLineOfThisRead = clone $scriptedActivityLine;
            $activityLines->add($activityLineOfThisRead);
        }
        return $activityLines;
    }

    public function narrate(
        AIConversation $conversation,
        AgentActivityLines $elements,
        ?int $turnInitiatorIndex = null,
        ?string $modelNameOverride = null,
        ?string $reasoningEffortOverride = null,
        array $alreadyTold = [],
    ): AgentActivityLines {
        $pendingActivityLines = $elements->withoutLine()->getElements();
        $this->narrateCalls[] = "turn=@{$turnInitiatorIndex} model=" . ($modelNameOverride ?? 'attribute') . ' effort=' . ($reasoningEffortOverride ?? 'attribute') . ' pending=' . count($pendingActivityLines);
        $this->lastInputTokens = 0;
        $this->lastOutputTokens = 0;
        $this->lastCostInUSD = 0.0;
        $this->lastDurationSeconds = 0.0;
        $this->lastUnparsedResults = [];
        foreach (array_chunk($pendingActivityLines, max(1, $this->elementsPerCall)) as $batchIndex => $batchOfActivityLines) {
            $this->lastInputTokens += 100 * count($batchOfActivityLines);
            $this->lastOutputTokens += 10 * count($batchOfActivityLines);
            $this->lastCostInUSD += 0.125 * count($batchOfActivityLines);
            $this->lastDurationSeconds += 0.25;
            $this->lastModelName = $modelNameOverride ?? 'scripted-narrator';
            $this->lastPayloadSample = "payload of batch {$batchIndex}";
            foreach ($batchOfActivityLines as $activityLine) {
                if ($activityLine->narratorToolName === self::REFUSED_TOOL_NAME) {
                    $this->lastUnparsedResults[] = 'not json for #' . $activityLine->toolCallMessageId;
                    continue;
                }
                $activityLine->line = "Line for #{$activityLine->toolCallMessageId} ({$activityLine->state})";
            }
        }
        return $elements;
    }
}
