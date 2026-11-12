<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The facts a space answers by default: the stored time zone (confirmed), its account as owner, and "not known here"
 * for everything only an application's subclass knows.
 */
class AgentSpaceTest extends UnitTestCase
{
    public function testAStoredTimezoneIsAnsweredAsConfirmed(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->timezone = 'Europe/Berlin';

        $agentSpaceTimezone = $agentSpace->findTimezone();

        self::assertInstanceOf(AgentSpaceTimezone::class, $agentSpaceTimezone);
        self::assertSame('Europe/Berlin', $agentSpaceTimezone->identifier);
        self::assertFalse($agentSpaceTimezone->unverified);
        self::assertSame('Europe/Berlin', $agentSpaceTimezone->getTimezone()->getName());
    }

    public function testWithoutAStoredTimezoneTheZoneIsNotKnown(): void
    {
        $agentSpace = new AgentSpace();
        self::assertNull($agentSpace->findTimezone());

        $agentSpace->timezone = '';
        self::assertNull($agentSpace->findTimezone());
    }

    public function testAStoredNameThatIsNoZoneIsNotKnown(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->timezone = 'Mars/Olympus_Mons';

        self::assertNull($agentSpace->findTimezone());
    }

    public function testATimezoneValueIsBuiltOnlyForAKnownIdentifier(): void
    {
        self::assertNull(AgentSpaceTimezone::ofKnownIdentifier('Not/AZone', true));

        $agentSpaceTimezone = AgentSpaceTimezone::ofKnownIdentifier('America/New_York', true);
        self::assertInstanceOf(AgentSpaceTimezone::class, $agentSpaceTimezone);
        self::assertTrue($agentSpaceTimezone->unverified);
    }

    public function testTheOwnerIsTheAccountOfTheSpace(): void
    {
        $account = new Account();
        $account->id = 12;
        $agentSpace = new AgentSpace();
        $agentSpace->accountId = 12;
        $agentSpace->account = $account;

        self::assertSame($account, $agentSpace->findOwnerAccount());
    }

    public function testARowReferencesAStoredSpaceByItsIdAndNoSpaceByNothing(): void
    {
        $storedAgentSpace = new AgentSpace();
        $storedAgentSpace->id = 7;

        self::assertSame(7, AgentSpace::getIdOfStoredAgentSpace($storedAgentSpace));
        self::assertNull(AgentSpace::getIdOfStoredAgentSpace(null));
    }

    public function testNoRowReferencesASpaceThatIsNotStored(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('An agent space without an id cannot be referenced by a row.');
        AgentSpace::getIdOfStoredAgentSpace(new AgentSpace());
    }

    public function testWhatOnlyAnApplicationKnowsIsNotKnownByDefault(): void
    {
        $agentSpace = new AgentSpace();

        self::assertNull($agentSpace->findBillingEntity());
        self::assertNull($agentSpace->findLoadedBillingEntity());
        self::assertNull($agentSpace->findLanguageCode());
        self::assertNull($agentSpace->findLocaleString());
    }
}
