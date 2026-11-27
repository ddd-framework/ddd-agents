<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\AgentConnectionTrigger;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\Connections\RecordingTriggersRunService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A new-mail trigger starts a run only for a new, authenticated mail of the poll window inside the label boundary
 * that matches its filters, and only once per mail.
 */
class AgentConnectionTriggerRunsTest extends UnitTestCase
{
    public function testOnlyANewAuthenticatedMatchingMailInsideTheBoundaryStartsARun(): void
    {
        $triggersRunService = new RecordingTriggersRunService();
        $agentConnection = $this->buildConnection();
        $agentConnection->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of(AgentConnectionScopeBoundary::KIND_LABEL, 'Support'));
        $agentConnectionTrigger = $this->buildTrigger();
        $recently = (new DateTime())->modify('-5 minutes');
        $triggersRunService->mailAdapter->addMail($this->buildMail('m1', 'Rechnung offen', $recently, ['Support'], true));
        $triggersRunService->mailAdapter->addMail($this->buildMail('m2', 'Rechnung alt', (new DateTime())->modify('-3 days'), ['Support'], true));
        $triggersRunService->mailAdapter->addMail($this->buildMail('m3', 'Rechnung gefälscht', $recently, ['Support'], false));
        $triggersRunService->mailAdapter->addMail($this->buildMail('m4', 'Rechnung privat', $recently, ['Privat'], true));
        $triggersRunService->mailAdapter->addMail($this->buildMail('m5', 'Newsletter', $recently, ['Support'], true));

        self::assertSame('1 runs started', $triggersRunService->pollTriggerForTest($agentConnection, $agentConnectionTrigger));
        self::assertSame(['Rechnung offen'], $triggersRunService->startedRunSubjects, 'old, unauthenticated, outside the boundary and not matching: no run');
        self::assertSame(1, $triggersRunService->firedMarks);

        self::assertSame('0 runs started', $triggersRunService->pollTriggerForTest($agentConnection, $agentConnectionTrigger), 'a mail seen before starts no second run');
    }

    public function testATriggerOfAnAccountWithoutReachStartsNothing(): void
    {
        $triggersRunService = new RecordingTriggersRunService();
        $agentConnectionTrigger = $this->buildTrigger();
        $agentConnectionTrigger->accountId = 99;
        $triggersRunService->mailAdapter->addMail($this->buildMail('m1', 'Rechnung offen', new DateTime(), [], true));
        self::assertSame('connection not reachable by the trigger account', $triggersRunService->pollTriggerForTest($this->buildConnection(), $agentConnectionTrigger));
        self::assertSame([], $triggersRunService->startedRunSubjects);
    }

    protected function buildConnection(): AgentConnection
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 31;
        $agentConnection->provider = 'IMAP';
        $agentConnection->ownership = AgentConnection::OWNERSHIP_ACCOUNT_GRANTED;
        $agentConnection->accountId = 4;
        return $agentConnection;
    }

    protected function buildTrigger(): AgentConnectionTrigger
    {
        $agentConnectionTrigger = AgentConnectionTrigger::newInstance();
        $agentConnectionTrigger->id = 8;
        $agentConnectionTrigger->agentConnectionId = 31;
        $agentConnectionTrigger->accountId = 4;
        $agentConnectionTrigger->eventKind = AgentConnectionTrigger::EVENT_NEW_MAIL;
        $agentConnectionTrigger->active = true;
        $agentConnectionTrigger->subjectFilter = 'Rechnung';
        $agentConnectionTrigger->lastFiredAt = (new DateTime())->modify('-1 hour');
        return $agentConnectionTrigger;
    }

    /** @param string[] $labels */
    protected function buildMail(string $messageId, string $subject, DateTime $sentAt, array $labels, bool $senderAuthenticated): AgentConnectionMailMessage
    {
        $mailMessage = AgentConnectionMailMessage::newInstance();
        $mailMessage->messageId = $messageId;
        $mailMessage->threadId = $messageId;
        $mailMessage->from = 'kunde@example.org';
        $mailMessage->subject = $subject;
        $mailMessage->sentAt = $sentAt;
        $mailMessage->labels = $labels;
        $mailMessage->senderAuthenticated = $senderAuthenticated;
        return $mailMessage;
    }
}
