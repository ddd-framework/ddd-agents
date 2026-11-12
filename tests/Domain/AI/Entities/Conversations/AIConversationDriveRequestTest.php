<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversationDriveRequest;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The drive request: the sanitized texts, the customer's empty-submit refusal and
 * the budget-bypass decision over its three inputs (an operating admin's request or the conversation's stored flag, the
 * console's process grant, the grant of the surrounding work — never system maintenance).
 */
class AIConversationDriveRequestTest extends UnitTestCase
{
    public function testTheTextsAreSanitizedAndTheStartSignalIsCapped(): void
    {
        $driveRequest = AIConversationDriveRequest::buildFor(AIConversationDriveScope::CUSTOMER, '  Hallo  ', str_repeat('x', AIConversationDriveRequest::MAX_START_SIGNAL_TEXT_CHARS + 10));
        self::assertFalse($driveRequest->isAdmin);
        self::assertTrue($driveRequest->hasOwnerText());
        self::assertSame(AIConversationDriveRequest::MAX_START_SIGNAL_TEXT_CHARS, mb_strlen((string)$driveRequest->sanitizedStartSignalText));
        self::assertNull(AIConversationDriveRequest::buildFor(AIConversationDriveScope::ADMIN, 'x', null)->sanitizedStartSignalText);
        self::assertTrue(AIConversationDriveRequest::buildFor(AIConversationDriveScope::ADMIN, 'x', null)->isAdmin);
    }

    public function testAnEmptyCustomerSubmitWithoutAStartSignalIsRefused(): void
    {
        AIConversationDriveRequest::buildFor(AIConversationDriveScope::CUSTOMER, '', 'The owner opened the widget.')->assertTheCustomerSentSomethingToDrive();
        AIConversationDriveRequest::buildFor(AIConversationDriveScope::ADMIN, '   ', null)->assertTheCustomerSentSomethingToDrive();
        $this->expectException(BadRequestException::class);
        AIConversationDriveRequest::buildFor(AIConversationDriveScope::CUSTOMER, '   ', '  ')->assertTheCustomerSentSomethingToDrive();
    }

    public function testTheBudgetBypassDecisionOverTheThreeInputs(): void
    {
        $consoleGrant = AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION);
        $conversationGrant = AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS);
        $maintenanceGrant = AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE);
        // [operating admin, requested, stored flag, process grant, surrounding grant] => [effective, grant reason]
        $rows = [
            'plain customer' => [[false, null, false, null, null], [false, null]],
            'customer asking for it is ignored' => [[false, true, true, null, null], [false, null]],
            'admin request' => [[true, true, false, null, null], [true, AIBudgetBypassGrant::REASON_ADMIN_REQUEST]],
            'admin continue keeps the stored flag' => [[true, null, true, null, null], [true, AIBudgetBypassGrant::REASON_ADMIN_REQUEST]],
            'admin switches it off' => [[true, false, true, null, null], [false, null]],
            'console --ignore-budget' => [[false, null, false, $consoleGrant, $consoleGrant], [true, AIBudgetBypassGrant::REASON_CONSOLE_OPTION]],
            'a bypass turn\'s tool starts the drive' => [[false, null, false, null, $conversationGrant], [true, AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS]],
            'system maintenance lifts only its own work' => [[false, null, false, null, $maintenanceGrant], [false, null]],
            'admin request wins over the console' => [[true, true, false, $consoleGrant, $consoleGrant], [true, AIBudgetBypassGrant::REASON_ADMIN_REQUEST]],
        ];
        foreach ($rows as $rowName => [[$adminIsOperatingSession, $budgetBypass, $storedFlag, $processGrant, $surroundingGrant], [$expectedEffective, $expectedReason]]) {
            $driveRequest = AIConversationDriveRequest::buildFor(AIConversationDriveScope::CUSTOMER, 'x', null);
            $driveRequest->decideBudgetBypass($adminIsOperatingSession, $budgetBypass, $storedFlag, $processGrant, $surroundingGrant);
            self::assertSame($expectedEffective, $driveRequest->effectiveBudgetBypass, $rowName . ' (persisted flag)');
            self::assertSame($expectedReason, $driveRequest->driveBudgetBypassGrant?->reason, $rowName . ' (grant)');
        }
    }
}
