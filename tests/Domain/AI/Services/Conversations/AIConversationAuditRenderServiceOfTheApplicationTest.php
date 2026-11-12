<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The module knows no removed outward tool: a tool an application once offered and removed is named by the
 * application's audit service of the same path, and the audit of a stored conversation lists its calls as before.
 */
class AIConversationAuditRenderServiceOfTheApplicationTest extends UnitTestCase
{
    public function testTheModuleNamesNoRemovedOutwardTool(): void
    {
        self::assertSame([], (new AIConversationAuditRenderService())->getRemovedOutwardActionToolNames());
        self::assertFalse(AIConversationAuditRenderService::isOutwardActionToolName('sendParcelOfTheShop'));
        self::assertTrue(AIConversationAuditRenderService::isOutwardActionToolName(SendOwnerReportTool::NAME));
    }

    public function testAnApplicationNamesTheOutwardToolsItRemoved(): void
    {
        self::assertSame(
            ['sendParcelOfTheShop'],
            (new AIConversationAuditRenderServiceOfAnApplicationThatOnceSentParcels())->getRemovedOutwardActionToolNames()
        );
    }
}

/** An application whose removed tool `sendParcelOfTheShop` still stands in stored conversations. */
class AIConversationAuditRenderServiceOfAnApplicationThatOnceSentParcels extends AIConversationAuditRenderService
{
    public function getRemovedOutwardActionToolNames(): array
    {
        return ['sendParcelOfTheShop'];
    }
}
