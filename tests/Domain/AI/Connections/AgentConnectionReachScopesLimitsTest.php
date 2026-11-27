<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCapabilityCeilings;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionConsentRequiredException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\Google\GoogleAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Tests\Agents\Support\Connections\InMemoryAgentConnectionsService;
use DDD\Tests\Agents\Support\Connections\LimitTestAgentConnectionOperationsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * In a conversation only a release to its own space counts; a level needs the provider's scopes before it takes
 * effect (incremental consent); the send limits count exactly.
 */
class AgentConnectionReachScopesLimitsTest extends UnitTestCase
{
    protected const string GMAIL_READ = 'https://www.googleapis.com/auth/gmail.readonly';
    protected const string GMAIL_COMPOSE = 'https://www.googleapis.com/auth/gmail.compose';
    protected const string GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    public function testInAConversationOnlyAReleaseToItsOwnSpaceCounts(): void
    {
        $agentConnectionsService = $this->buildServiceWithReleasesTo(13, 14);
        $agentConnectionsService->memberAgentSpaceIdsByAccountId[5] = [13, 14];
        $agentConnection = $this->buildConnection(ownerAccountId: 1);

        self::assertSame(13, $agentConnectionsService->getAccessOfAccount($agentConnection, 5, AgentConnectionReach::inSpace(13))?->agentConnectionRelease?->agentSpaceId);
        self::assertSame(14, $agentConnectionsService->getAccessOfAccount($agentConnection, 5, AgentConnectionReach::inSpace(14))?->agentConnectionRelease?->agentSpaceId);
        self::assertNull($agentConnectionsService->getAccessOfAccount($agentConnection, 5, AgentConnectionReach::inSpace(15)), 'a release to another space never reaches into this conversation');
        self::assertNull($agentConnectionsService->getAccessOfAccount($agentConnection, 5, AgentConnectionReach::inSpace(null)), 'a conversation without a space reaches only own connections');
        self::assertNotNull($agentConnectionsService->getAccessOfAccount($agentConnection, 5, AgentConnectionReach::acrossMemberSpaces()), 'the management view sees every member space');
        self::assertNull($agentConnectionsService->getAccessOfAccount($agentConnection, 6, AgentConnectionReach::acrossMemberSpaces()), 'no member, no access');
        self::assertSame(AgentConnectionAccess::KIND_OWNER, $agentConnectionsService->getAccessOfAccount($agentConnection, 1, AgentConnectionReach::inSpace(15))?->kind, 'outside a conversation the owner reaches its own connection');
    }

    public function testInAConversationOthersReadTheOwnerNeedsAReleaseToItsSpace(): void
    {
        $agentConnectionsService = $this->buildServiceWithReleasesTo(14);
        $agentConnectionsService->memberAgentSpaceIdsByAccountId[1] = [13, 14];
        $agentConnectionsService->conversationIdsReadByOthers = [700, 701];
        $agentConnection = $this->buildConnection(ownerAccountId: 1);

        self::assertSame(AgentConnectionAccess::KIND_OWNER, $agentConnectionsService->getAccessOfAccount($agentConnection, 1, $this->buildCallReach(13, 600))?->kind, 'a conversation only the owner reads: own access');
        self::assertNull($agentConnectionsService->getAccessOfAccount($agentConnection, 1, $this->buildCallReach(13, 700)), 'a shared conversation of a space the connection is not released to: nothing');
        self::assertSame(14, $agentConnectionsService->getAccessOfAccount($agentConnection, 1, $this->buildCallReach(14, 701))?->agentConnectionRelease?->agentSpaceId, 'a shared conversation of the released space: the release, with its ceilings');
        self::assertSame(AgentConnectionAccess::KIND_OWNER, $agentConnectionsService->getAccessOfAccount($agentConnection, 1, AgentConnectionReach::acrossMemberSpaces())?->kind, 'the management view stays the owner\'s');
    }

    public function testThePackageCountsAConversationAsReadByOthersUnlessTheHostSaysOtherwise(): void
    {
        $agentConnectionsService = new AgentConnectionsService();
        self::assertTrue($agentConnectionsService->isConversationReadByOthers(600, $this->buildConnection(ownerAccountId: 1)), 'fail closed');
    }

    protected function buildCallReach(int $agentSpaceId, int $conversationId): AgentConnectionReach
    {
        $callProvenance = new AgentToolCallProvenance(conversationId: $conversationId);
        $callProvenance->agentSpaceId = $agentSpaceId;
        return AgentConnectionReach::ofCall($callProvenance);
    }

    public function testTheReleaseCeilingCapsTheEffectiveLevel(): void
    {
        $agentConnectionsService = $this->buildServiceWithReleasesTo(13);
        $agentConnectionsService->memberAgentSpaceIdsByAccountId[5] = [13];
        $this->addPermission($agentConnectionsService, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL);
        $agentConnection = $this->buildConnection(ownerAccountId: 1);
        self::assertSame(AgentConnectionPermission::LEVEL_READ, $agentConnectionsService->getEffectiveLevel($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, 5, AgentConnectionReach::inSpace(13)));
        self::assertSame(AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL, $agentConnectionsService->getEffectiveLevel($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, 1, AgentConnectionReach::inSpace(13)));
        self::assertSame(AgentConnectionPermission::LEVEL_OFF, $agentConnectionsService->getEffectiveLevel($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, 5, AgentConnectionReach::inSpace(14)));
    }

    public function testScopesAreCumulativePerLevelAndBroaderScopesImplyNarrowerOnes(): void
    {
        $googleAdapter = new GoogleAgentConnectionAdapter();
        self::assertSame([self::GMAIL_READ], $googleAdapter->getScopesFor(AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_READ));
        self::assertSame([self::GMAIL_READ, self::GMAIL_COMPOSE], $googleAdapter->getScopesFor(AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL), 'a level without own scopes needs those of the levels below');
        self::assertSame([], $googleAdapter->findMissingScopes([self::GMAIL_READ], AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_READ));
        self::assertSame([self::GMAIL_COMPOSE], array_values($googleAdapter->findMissingScopes([self::GMAIL_READ], AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_DRAFT)));
        self::assertSame([], $googleAdapter->findMissingScopes([self::GMAIL_MODIFY, self::GMAIL_COMPOSE], AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_DRAFT), 'gmail.modify covers the read scope');
        self::assertNotSame([], $googleAdapter->findMissingScopes([self::GMAIL_READ], AgentConnectionPermission::CAPABILITY_FILES, AgentConnectionPermission::LEVEL_READ), 'another capability is not covered');
    }

    public function testALevelAboveTheGrantedScopesNeedsAConsentFirstAndChangesNothing(): void
    {
        $agentConnectionsService = $this->buildServiceWithReleasesTo();
        $agentConnectionsService->oAuthAdapter = new GoogleAgentConnectionAdapter();
        $mailPermission = $this->addPermission($agentConnectionsService, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_READ);
        $agentConnection = $this->buildConnection(ownerAccountId: 1);
        $agentConnection->grantedScopes = self::GMAIL_READ;
        self::assertSame(AgentConnectionPermission::LEVEL_READ, $agentConnectionsService->findHighestGrantedLevel($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL));
        try {
            $agentConnectionsService->setLevel($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionPermission::LEVEL_DRAFT, 1);
            self::fail('a level whose scopes are missing needs a consent first');
        } catch (AgentConnectionConsentRequiredException $consentRequired) {
            self::assertSame([self::GMAIL_COMPOSE], array_values($consentRequired->missingScopes));
            self::assertStringStartsWith('consent_required', $consentRequired->getMessage());
        }
        self::assertSame(AgentConnectionPermission::LEVEL_READ, $mailPermission->level, 'the level changes only after the consent');
    }

    public function testTheSendLimitsCountPerConnectionAndPerExactTarget(): void
    {
        $operationsService = new LimitTestAgentConnectionOperationsService();
        $agentConnection = $this->buildConnection(ownerAccountId: 1);
        $agentConnection->sendsPerHourLimit = 3;
        $agentConnection->sendsPerDayLimit = 10;
        $agentConnection->sendsPerRecipientPerDayLimit = 2;
        $agentConnectionOperation = AgentConnectionOperation::create($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionAction::OPERATION_SEND, 'sendMail', 1, null);
        $agentConnectionOperation->recipients = ['anna@example.org'];

        $operationsService->requireLimitsForTest($agentConnectionOperation);
        $operationsService->countingActionsService->doneByTarget[$operationsService::describeTarget('anna.b@example.org')] = 5;
        $operationsService->requireLimitsForTest($agentConnectionOperation);
        $this->addToAssertionCount(1);

        $operationsService->countingActionsService->doneByTarget[$operationsService::describeTarget('anna@example.org')] = 2;
        $this->assertRefusedByLimit($operationsService, $agentConnectionOperation, 'the per-recipient limit');

        $operationsService->countingActionsService->doneByTarget = [];
        $operationsService->countingActionsService->doneInTheLastHour = 3;
        $this->assertRefusedByLimit($operationsService, $agentConnectionOperation, 'the hourly limit');
    }

    protected function assertRefusedByLimit(LimitTestAgentConnectionOperationsService $operationsService, AgentConnectionOperation $agentConnectionOperation, string $limitName): void
    {
        try {
            $operationsService->requireLimitsForTest($agentConnectionOperation);
            self::fail($limitName . ' must refuse');
        } catch (AgentConnectionRefusedException) {
            self::assertSame(AgentConnectionAction::OUTCOME_REFUSED_BY_LIMIT, end($operationsService->countingActionsService->recordedOutcomes), $limitName . ' is audited');
        }
    }

    protected function buildServiceWithReleasesTo(int ...$agentSpaceIds): InMemoryAgentConnectionsService
    {
        $agentConnectionsService = new InMemoryAgentConnectionsService();
        foreach ($agentSpaceIds as $agentSpaceId) {
            $agentConnectionRelease = AgentConnectionRelease::newInstance();
            $agentConnectionRelease->id = $agentSpaceId * 10;
            $agentConnectionRelease->targetType = AgentConnectionRelease::TARGET_AGENT_SPACE;
            $agentConnectionRelease->agentSpaceId = $agentSpaceId;
            $agentConnectionRelease->capabilityCeilings = AgentConnectionCapabilityCeilings::fromLevelsByCapability([AgentConnectionPermission::CAPABILITY_MAIL => AgentConnectionPermission::LEVEL_READ]);
            $agentConnectionsService->releases->add($agentConnectionRelease);
        }
        return $agentConnectionsService;
    }

    protected function addPermission(InMemoryAgentConnectionsService $agentConnectionsService, string $capability, string $level): AgentConnectionPermission
    {
        $agentConnectionPermission = AgentConnectionPermission::newInstance();
        $agentConnectionPermission->id = count($agentConnectionsService->permissions->getElements()) + 1;
        $agentConnectionPermission->capability = $capability;
        $agentConnectionPermission->level = $level;
        $agentConnectionsService->permissions->add($agentConnectionPermission);
        return $agentConnectionPermission;
    }

    protected function buildConnection(int $ownerAccountId): AgentConnection
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 77;
        $agentConnection->provider = 'GOOGLE';
        $agentConnection->ownership = AgentConnection::OWNERSHIP_ACCOUNT_GRANTED;
        $agentConnection->accountId = $ownerAccountId;
        return $agentConnection;
    }
}
