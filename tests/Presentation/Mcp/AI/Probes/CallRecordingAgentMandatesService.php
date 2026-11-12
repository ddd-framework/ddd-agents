<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantOutcome;
use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;

/** The mandate service without a database: keeps what a tool asked it for and answers with what the test set. */
class CallRecordingAgentMandatesService extends AgentMandatesService
{
    /** @var array<int, array{accountId: int, agentSpace: AgentSpace|null}> one entry per list read */
    public array $listReads = [];

    /** @var array<int, array{accountId: int, agentSpace: AgentSpace|null, grantArguments: AgentMandateGrantArguments, sourceConversationId: int|null}> one entry per grant */
    public array $grants = [];

    /** @var AgentMandate|null the mandate a grant reports as already covering, and a revoke returns (null = none of the account) */
    public ?AgentMandate $agentMandateTheServiceAnswers = null;

    public function findMandatesForAccount(int $accountId, ?AgentSpace $agentSpace = null): AgentMandates
    {
        $this->listReads[] = ['accountId' => $accountId, 'agentSpace' => $agentSpace];
        return new AgentMandates();
    }

    public function grantMandateFromDialogue(
        int $accountId,
        ?AgentSpace $agentSpace,
        AgentMandateGrantArguments $grantArguments,
        AIConversationMessages $conversationMessages,
        ?AIConversationMessage $sourceUserMessage = null,
        ?int $sourceConversationId = null,
        ?\DateTimeZone $dialogueTimezone = null,
    ): AgentMandateGrantOutcome {
        $this->grants[] = ['accountId' => $accountId, 'agentSpace' => $agentSpace, 'grantArguments' => $grantArguments, 'sourceConversationId' => $sourceConversationId];
        return AgentMandateGrantOutcome::forAlreadyCovered($this->agentMandateTheServiceAnswers);
    }

    public function revokeMandateOfAccount(int $accountId, int $mandateId, ?int $revokedByMessageId): ?AgentMandate
    {
        return $this->agentMandateTheServiceAnswers;
    }
}
