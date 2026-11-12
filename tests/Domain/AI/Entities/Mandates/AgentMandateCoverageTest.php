<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What a mandate covers, read from its space alone: a mandate without a space is account-wide, a mandate with one is
 * bound to it. A new grant replaces the standing mandates of the same class it covers — never one of another space,
 * and a grant bound to a space never replaces an account-wide mandate.
 */
class AgentMandateCoverageTest extends UnitTestCase
{
    public function testANewGrantOfTheSameSpaceReplacesTheStandingOne(): void
    {
        self::assertTrue($this->buildMandate(7)->isSupersededBy($this->buildMandate(7)));
    }

    public function testANewGrantOfAnotherSpaceReplacesNothing(): void
    {
        self::assertFalse($this->buildMandate(7)->isSupersededBy($this->buildMandate(8)));
    }

    public function testANewAccountWideGrantReplacesTheMandatesOfEverySpaceAndTheAccountWideOne(): void
    {
        self::assertTrue($this->buildMandate(7)->isSupersededBy($this->buildMandate(null)));
        self::assertTrue($this->buildMandate(null)->isSupersededBy($this->buildMandate(null)));
    }

    public function testANewGrantBoundToASpaceNeverReplacesAnAccountWideMandate(): void
    {
        self::assertFalse($this->buildMandate(null)->isSupersededBy($this->buildMandate(7)));
    }

    public function testAGrantOfAnotherActionClassReplacesNothing(): void
    {
        $newerMandateOfAnotherClass = $this->buildMandate(null);
        $newerMandateOfAnotherClass->actionClass = 'AN_ACTION_CLASS_OF_AN_APPLICATION';

        self::assertFalse($this->buildMandate(7)->isSupersededBy($newerMandateOfAnotherClass));
        self::assertFalse($this->buildMandate(null)->isSupersededBy($newerMandateOfAnotherClass));
    }

    public function testTheListLineNamesTheSpaceOfABoundMandateAndSaysAccountWideForOneWithout(): void
    {
        self::assertSame(
            '- [17] STANDING_AUTOMATION · until revoked · ACTIVE · space 7',
            $this->buildMandate(7)->toLlmMarkdownInZone(null)
        );
        self::assertSame(
            '- [17] STANDING_AUTOMATION · until revoked · ACTIVE · account-wide',
            $this->buildMandate(null)->toLlmMarkdownInZone(null)
        );
    }

    /** An ACTIVE mandate without an end; `$agentSpaceId` null = account-wide. */
    protected function buildMandate(?int $agentSpaceId): AgentMandate
    {
        $agentMandate = new AgentMandate();
        $agentMandate->id = 17;
        $agentMandate->accountId = 12;
        $agentMandate->agentSpaceId = $agentSpaceId;
        $agentMandate->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $agentMandate->scopeType = AgentMandate::SCOPE_UNLIMITED;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        return $agentMandate;
    }
}
