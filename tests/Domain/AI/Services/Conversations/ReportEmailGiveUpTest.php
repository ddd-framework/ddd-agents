<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DateTimeImmutable;

/**
 * The owed-report e-mail retry stops after a day: a run whose report mail cannot be delivered (a test account's
 * example.com address, a dead mailbox) looped through the five-minute sweep for a week before this rule existed.
 */
class ReportEmailGiveUpTest extends UnitTestCase
{
    public function testRetriesInsideTheFirstDay(): void
    {
        $now = new DateTimeImmutable('2026-09-18 12:00:00');
        self::assertFalse(AIConversationOwnerReportDeliveryService::reportEmailRetryWindowExpired(new DateTimeImmutable('2026-09-18 11:00:00'), $now));
        self::assertFalse(AIConversationOwnerReportDeliveryService::reportEmailRetryWindowExpired(new DateTimeImmutable('2026-09-17 12:00:00'), $now), 'exactly one day is still inside the window');
    }

    public function testGivesUpAfterADay(): void
    {
        $now = new DateTimeImmutable('2026-09-18 12:00:00');
        self::assertTrue(AIConversationOwnerReportDeliveryService::reportEmailRetryWindowExpired(new DateTimeImmutable('2026-09-17 11:59:59'), $now));
        self::assertTrue(AIConversationOwnerReportDeliveryService::reportEmailRetryWindowExpired(new DateTimeImmutable('2026-09-11 14:10:08'), $now), 'the week-old test-account run');
    }

    public function testUnknownStartNeverGivesUp(): void
    {
        self::assertFalse(AIConversationOwnerReportDeliveryService::reportEmailRetryWindowExpired(null));
    }
}
