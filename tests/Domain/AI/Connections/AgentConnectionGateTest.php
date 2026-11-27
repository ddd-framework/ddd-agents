<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundary;
use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCapabilityCeilings;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAdapterSettings;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionProviderCall;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;
use DDD\Domain\AI\Services\Connections\AgentConnectionCredentialSetsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure parts of the AgentConnections gate: the ordered levels and what each operation needs, release ceilings,
 * boundary intersection (fail closed), approval expiry, the provider call object, the adapter settings, and the
 * encryption round trip of stored secrets.
 */
class AgentConnectionGateTest extends UnitTestCase
{
    public function testEveryOperationNeedsItsLevel(): void
    {
        self::assertSame(AgentConnectionPermission::LEVEL_READ, AgentConnectionPermission::levelNeededFor('SEARCH', false));
        self::assertSame(AgentConnectionPermission::LEVEL_DRAFT, AgentConnectionPermission::levelNeededFor('DRAFT', false));
        foreach (['CREATE', 'UPDATE', 'LINK', 'UNLINK'] as $operation) {
            self::assertSame(AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL, AgentConnectionPermission::levelNeededFor($operation, false), $operation);
        }
        self::assertSame(AgentConnectionPermission::LEVEL_DELETE_WITH_APPROVAL, AgentConnectionPermission::levelNeededFor('DELETE', false));
        self::assertSame(AgentConnectionPermission::LEVEL_DELETE_WITH_APPROVAL, AgentConnectionPermission::levelNeededFor('MERGE', false), 'a merge deletes its donor');
        self::assertSame(AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL, AgentConnectionPermission::levelNeededFor('SEND', false));
        self::assertSame(AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL, AgentConnectionPermission::levelNeededFor('CREATE', true), 'an event with attendees reaches people');
    }

    public function testLevelsAreOrderedAndTheLowerOneWins(): void
    {
        $previousRank = -1;
        foreach (AgentConnectionPermission::LEVELS as $level) {
            self::assertGreaterThan($previousRank, AgentConnectionPermission::rankOfLevel($level));
            $previousRank = AgentConnectionPermission::rankOfLevel($level);
        }
        self::assertSame(AgentConnectionPermission::LEVEL_READ, AgentConnectionPermission::lowerLevel(AgentConnectionPermission::LEVEL_SEND_BY_RULE, AgentConnectionPermission::LEVEL_READ));
        self::assertSame(0, AgentConnectionPermission::rankOfLevel('UNKNOWN'), 'an unknown level ranks as OFF');
    }

    public function testAReleaseCeilingCapsTheReach(): void
    {
        $agentConnectionRelease = AgentConnectionRelease::newInstance();
        $agentConnectionRelease->capabilityCeilings = AgentConnectionCapabilityCeilings::fromLevelsByCapability([
            AgentConnectionPermission::CAPABILITY_MAIL => AgentConnectionPermission::LEVEL_READ,
            AgentConnectionPermission::CAPABILITY_CALENDAR => 'NOT_A_LEVEL',
        ]);
        $agentConnectionAccess = AgentConnectionAccess::throughRelease($agentConnectionRelease);
        self::assertSame(AgentConnectionPermission::LEVEL_READ, $agentConnectionAccess->getCeiling(AgentConnectionPermission::CAPABILITY_MAIL));
        self::assertSame(AgentConnectionPermission::LEVEL_OFF, $agentConnectionAccess->getCeiling(AgentConnectionPermission::CAPABILITY_CALENDAR), 'an invalid ceiling is no ceiling: off');
        self::assertSame(AgentConnectionPermission::LEVEL_OFF, $agentConnectionAccess->getCeiling(AgentConnectionPermission::CAPABILITY_FILES), 'a capability not released is off');
    }

    public function testBoundariesIntersectAndFailClosed(): void
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of('LABEL', 'Team'), AgentConnectionScopeBoundary::of('LABEL', 'Support'), AgentConnectionScopeBoundary::of('CALENDAR', 'primary'));
        self::assertNull(AgentConnectionAccess::asOwner()->getAllowedBoundaryValues($agentConnection, 'FOLDER'), 'no boundary of a kind: no limit');
        self::assertSame(['Team', 'Support'], AgentConnectionAccess::asOwner()->getAllowedBoundaryValues($agentConnection, 'LABEL'));

        $agentConnectionRelease = AgentConnectionRelease::newInstance();
        $agentConnectionRelease->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of('LABEL', 'Support'));
        self::assertSame(['Support'], AgentConnectionAccess::throughRelease($agentConnectionRelease)->getAllowedBoundaryValues($agentConnection, 'LABEL'));

        $agentConnectionRelease->scopeBoundaries = AgentConnectionScopeBoundaries::ofBoundaries(AgentConnectionScopeBoundary::of('LABEL', 'Finance'));
        self::assertSame([], AgentConnectionAccess::throughRelease($agentConnectionRelease)->getAllowedBoundaryValues($agentConnection, 'LABEL'), 'disjoint boundaries allow nothing');
    }

    public function testAnExpiredApprovalIsNoLongerOpen(): void
    {
        $agentConnectionApprovalRequest = AgentConnectionApprovalRequest::newInstance();
        $agentConnectionApprovalRequest->status = AgentConnectionApprovalRequest::STATUS_OPEN;
        $agentConnectionApprovalRequest->expiresAt = (new DateTime())->modify('+1 hour');
        self::assertTrue($agentConnectionApprovalRequest->isOpen());
        $agentConnectionApprovalRequest->expiresAt = (new DateTime())->modify('-1 minute');
        self::assertFalse($agentConnectionApprovalRequest->isOpen());
        self::assertTrue($agentConnectionApprovalRequest->isExpired());
    }

    public function testAProviderCallRunsItsMethodWithItsArguments(): void
    {
        $providerCall = AgentConnectionProviderCall::of(new \ArrayObject(['a' => 1]), 'offsetGet', 'a');
        self::assertSame(1, $providerCall());
        self::assertNull($providerCall->cacheQuery);
        self::assertSame('q', $providerCall->cachedAs('q')->cacheQuery);
    }

    public function testAdapterSettingsAreTypedWithSafeDefaults(): void
    {
        $adapterSettings = AgentConnectionAdapterSettings::fromConfiguration(['enabled' => 1, 'allowedHostedDomains' => 'example.com']);
        self::assertTrue($adapterSettings->enabled);
        self::assertSame(['example.com'], $adapterSettings->allowedHostedDomains);
        self::assertFalse($adapterSettings->answerInUnnamedChannels, 'unnamed channels are not answered by default');
        self::assertSame('', $adapterSettings->botTokenEnvironmentVariable);
    }

    public function testSecretsRoundTripOnlyWithTheirKey(): void
    {
        $encryptionKey = str_repeat('k', 32);
        $encrypted = Encrypt::encrypt('{"refreshToken":"secret"}', $encryptionKey);
        self::assertIsString($encrypted);
        self::assertStringNotContainsString('secret', $encrypted);
        self::assertSame('{"refreshToken":"secret"}', Encrypt::decrypt($encrypted, $encryptionKey));
        self::assertNotSame('{"refreshToken":"secret"}', Encrypt::decrypt($encrypted, str_repeat('x', 32)));
        self::assertNotSame(AgentConnectionCredentialSetsService::fingerprintOfKey($encryptionKey), AgentConnectionCredentialSetsService::fingerprintOfKey(str_repeat('x', 32)), 'a copied row under another key is recognised');
    }
}
