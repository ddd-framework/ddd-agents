<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A conversation's tool calls replayed through the activity narrator with one model (the activity-lines console
 * command, which compares models side by side): the turns with their lines, and the totals of the narrator calls.
 * Identified by its place in the comparison ({@see AgentActivityLineModelReplays}).
 *
 * @phpstan-consistent-constructor
 */
class AgentActivityLineModelReplay extends ValueObject
{
    /** @var int The place of the model in the comparison, from 0 */
    public int $comparisonPosition = 0;

    /** @var string The model that answered (the requested model and effort until the first call names it) */
    public string $modelName = '';

    /** @var AgentActivityLineTurnReplays The replayed turns */
    public AgentActivityLineTurnReplays $turnReplays;

    /** @var int Input tokens of all narrator calls */
    public int $inputTokens = 0;

    /** @var int Output tokens of all narrator calls */
    public int $outputTokens = 0;

    /** @var float Cost of all narrator calls in USD */
    public float $costUsd = 0.0;

    /** @var float Duration of all narrator calls in seconds */
    public float $seconds = 0.0;

    /** @var int The number of narrator calls */
    public int $narratorCallCount = 0;

    /** @var int Elements that wanted a line and got one, counted per moment */
    public int $lineCount = 0;

    /** @var int Elements that wanted a line and got none, counted per moment */
    public int $missingLineCount = 0;

    public function __construct()
    {
        parent::__construct();
        $this->turnReplays = new AgentActivityLineTurnReplays();
    }

    /**
     * @param int $comparisonPosition the place of the model in the comparison, from 0
     * @param string $modelName the requested model and effort
     * @return static a replay without turns yet
     */
    public static function forModel(int $comparisonPosition, string $modelName): static
    {
        $agentActivityLineModelReplay = new static();
        $agentActivityLineModelReplay->comparisonPosition = $comparisonPosition;
        $agentActivityLineModelReplay->modelName = $modelName;
        return $agentActivityLineModelReplay;
    }

    public function uniqueKey(): string
    {
        return self::class . '_' . $this->comparisonPosition;
    }
}
