<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An eval run's row carries the case's report channel (EMAIL) since the run identity is persisted: the mail leg — the
 * queued message's handler and the recovery cron's late delivery both end in `composeAndSendReportEmail()` — never
 * sends an owner mail for it, nor for a delegation child of such a run.
 */
class EvalRunOwnerReportMailGuardTest extends UnitTestCase
{
    public function testAnEvalRunSendsNoMailAndKeepsItsClaim(): void
    {
        $conversation = $this->emailConversation(7, evalRun: true);
        $service = $this->serviceWithoutTheMailPath();
        $service->expects(self::never())->method('getOwnerReportEmailsService');
        $service->expects(self::never())->method('handleReportObligationForIneligibleScope');
        $service->expects(self::never())->method('revertReportDeliveryClaim');
        $service->expects(self::once())->method('aiTrace')->with('reportEmail.skippedForEvalRun', ['conversationId' => 7]);

        self::assertFalse($service->composeAndSendReportEmail($conversation, async: false), 'the handler path sends nothing');
    }

    public function testAChildOfAnEvalRunIsAnEvalRunTreeAndARealRunIsNot(): void
    {
        $evalRoot = $this->emailConversation(10, evalRun: true);
        $child = $this->emailConversation(11, evalRun: false);
        $child->rootConversationId = 10;
        $service = $this->serviceFindingRoot($evalRoot);
        self::assertTrue($service->belongsToAnEvalRunTree($child));

        $realRoot = $this->emailConversation(20, evalRun: false);
        $realChild = $this->emailConversation(21, evalRun: false);
        $realChild->rootConversationId = 20;
        self::assertFalse($this->serviceFindingRoot($realRoot)->belongsToAnEvalRunTree($realChild));
        self::assertFalse($this->serviceFindingRoot($realRoot)->belongsToAnEvalRunTree($realRoot), 'a root without the marker');
    }

    public function testARealEmailRunStillReachesTheMailPath(): void
    {
        $conversation = $this->emailConversation(30, evalRun: false);
        $service = $this->serviceWithoutTheMailPath();
        $service->expects(self::once())->method('handleReportObligationForIneligibleScope')->willReturn(true);

        self::assertFalse($service->composeAndSendReportEmail($conversation), 'past the eval guard: the eligibility check ran');
    }

    protected function emailConversation(int $id, bool $evalRun): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $id;
        $conversation->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        $conversation->rootConversationId = null;
        if ($evalRun) {
            $runSettings = new RunSettings();
            $runSettings->evalRun = true;
            $conversation->runSettings = $runSettings;
        }
        return $conversation;
    }

    /** @return AIConversationOwnerReportDeliveryService&\PHPUnit\Framework\MockObject\MockObject */
    protected function serviceWithoutTheMailPath(): AIConversationOwnerReportDeliveryService
    {
        return $this->getMockBuilder(AIConversationOwnerReportDeliveryService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleReportObligationForIneligibleScope', 'getOwnerReportEmailsService', 'revertReportDeliveryClaim', 'deferOwedReport', 'aiTrace'])
            ->getMock();
    }

    /** The service with the conversations service replaced: its `find()` answers the given root for the root's id. */
    protected function serviceFindingRoot(AIConversation $rootConversation): AIConversationOwnerReportDeliveryService
    {
        $aIConversationsService = $this->getMockBuilder(AIConversationsService::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
        $aIConversationsService->method('find')->with($rootConversation->id)->willReturn($rootConversation);
        $service = $this->getMockBuilder(AIConversationOwnerReportDeliveryService::class)
            ->disableOriginalConstructor()->onlyMethods(['conversationsService'])->getMock();
        $service->method('conversationsService')->willReturn($aIConversationsService);
        return $service;
    }
}
