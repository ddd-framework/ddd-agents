<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The tool-call child with an envelope service handed in and the tool execution replaced by a probe that records the
 * budget grant and the envelope depth the tool would see. For a whole run the row and its conversation are handed in
 * ({@see $toolCallMessageOfRun}, {@see $conversationOfRun}) together with the doubles of the messages and the activity
 * lines services.
 */
class BudgetLiftRecordingExecuteToolCallCommand extends AgentExecuteToolCallCommand
{
    /** @var string|null The reason of the budget grant during the tool; null = no grant */
    public ?string $grantReasonDuringTool = null;

    /** @var int|null The envelope depth during the tool */
    public ?int $envelopeDepthDuringTool = null;

    /** @var Throwable|null What the tool throws; null = it answers */
    public ?Throwable $toolFailure = null;

    /** @var AIConversationMessage|null The tool call row a whole run works on */
    public ?AIConversationMessage $toolCallMessageOfRun = null;

    /** @var AIConversation|null The conversation of that row */
    public ?AIConversation $conversationOfRun = null;

    /** @var ToolCallOutcomeRecordingMessagesServiceDouble The messages service of a whole run */
    public ToolCallOutcomeRecordingMessagesServiceDouble $messagesServiceDouble;

    /**
     * @param RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble
     */
    public function __construct(protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble)
    {
        $this->messagesServiceDouble = new ToolCallOutcomeRecordingMessagesServiceDouble();
        parent::__construct('app:test:execute-tool-call-probe');
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        return $this->messagesServiceDouble;
    }

    protected function getAgentActivityLinesService(): AgentActivityLinesService
    {
        return new ActivityLinesServiceWithoutReadersDouble();
    }

    protected function loadToolCallMessageAndConversation(int $messageId, string $parentRunToken, OutputInterface $output): bool
    {
        $this->toolCallMessage = $this->toolCallMessageOfRun;
        $this->conversationOfToolCall = $this->conversationOfRun;
        return $this->toolCallMessage !== null && $this->conversationOfToolCall !== null;
    }

    protected function executeUnderTimeout(ToolCall $toolCall, AIConversation $conversation, int $timeoutSeconds): ToolResult
    {
        $this->grantReasonDuringTool = $this->usageEnvelopesServiceDouble->getBudgetBypassGrant()?->reason;
        $this->envelopeDepthDuringTool = $this->usageEnvelopesServiceDouble->getNestingDepth();
        if ($this->toolFailure !== null) {
            throw $this->toolFailure;
        }
        return new ToolResult('done');
    }

    /**
     * Runs the envelope step of the child for one call.
     *
     * @param AIConversation $conversation
     * @return ToolResult
     */
    public function runToolInsideEnvelope(AIConversation $conversation): ToolResult
    {
        return $this->executeInsideToolCallEnvelope(new ToolCall(), $conversation, 60);
    }
}
