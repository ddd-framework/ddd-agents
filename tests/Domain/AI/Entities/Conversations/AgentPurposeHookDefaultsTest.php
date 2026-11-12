<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\ScheduleRunPurpose;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The purpose and widget hooks of the core take the space the conversation works for (none at account level) and
 * default to "nothing": no start effect, no turn-start notice or widget, no tool result that ends the purpose, the
 * default delegation verdict, no channel state, no warm, no criterion verdict, no edit applied at turn open.
 */
class AgentPurposeHookDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // an entity reads the default lazy-load repo type on construction; the package's borrowed test environment may not set it
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheBasePurposeHooksDoNothing(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $agentSpace = $this->buildStoredAgentSpace(12);
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->agentSpaceId = 12;
        $conversation->agentSpace = $agentSpace;

        $aIConversationPurpose->afterPurposedStart($agentSpace);
        $aIConversationPurpose->afterPurposedStart(null);
        self::assertNull($aIConversationPurpose->buildTurnStartNotice($conversation));
        self::assertNull($aIConversationPurpose->buildWidgetAtTurnOpen($conversation));
        self::assertFalse($aIConversationPurpose->doesToolResultEndThePurpose('deliverSetupHandover', '{"structuredContent":{"outcome":"RENDERED"}}'));
        self::assertNull($aIConversationPurpose->buildDelegationResultVerdict($conversation));
        self::assertNull($aIConversationPurpose->buildDelegationResultTaskText($conversation));
        self::assertNull($aIConversationPurpose->findChannelConnectionStateOfAgentSpace($agentSpace, 'google'));
        self::assertSame([], $aIConversationPurpose->backgroundWarmOperationsAfterStep(['updateProfile'], $agentSpace, $conversation));
        self::assertSame([], $aIConversationPurpose->backgroundWarmOperationsAfterStep(['updateProfile'], null, $conversation));
        self::assertSame(0, $aIConversationPurpose->resolveVariants($conversation)->count());
    }

    public function testTheDraftApprovalRuleDropsTheKeyUnderAShortLine(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        self::assertTrue($aIConversationPurpose->isFreeQuestionUnderDraftApproval(str_repeat('x', AIConversationPurpose::DRAFT_APPROVAL_MIN_LINE_CHARS - 1)));
        self::assertFalse($aIConversationPurpose->isFreeQuestionUnderDraftApproval(str_repeat('x', AIConversationPurpose::DRAFT_APPROVAL_MIN_LINE_CHARS)));
    }

    public function testTheCriterionHooksTakeTheSpaceAndBuildTheRecordConversationOverIt(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Record', 'doneCriterion' => 'probe.record']]);
        $agentSpace = $this->buildStoredAgentSpace(12);
        self::assertNull($aIConversationPurpose->resolveDoneCriterion('probe.record', $agentSpace));
        self::assertNull($aIConversationPurpose->resolveDoneCriterion('probe.record', null));
        self::assertSame(0, $aIConversationPurpose->preResolveSeedTaskPlanItems($taskPlanItems, $agentSpace, new AIConversationMessages()));
        self::assertSame(0, $aIConversationPurpose->preResolveSeedTaskPlanItems($taskPlanItems, null, new AIConversationMessages()));

        $conversationMessages = new AIConversationMessages();
        $recordConversation = $aIConversationPurpose->buildConversationOfTheRecordPieces(5, $agentSpace, $conversationMessages);
        self::assertInstanceOf(AIConversation::class, $recordConversation);
        self::assertSame(5, $recordConversation->id);
        self::assertSame(12, $recordConversation->agentSpaceId);
        self::assertSame($agentSpace, $recordConversation->agentSpace);
        self::assertSame($conversationMessages, $recordConversation->messages);
        $recordConversationAtAccountLevel = $aIConversationPurpose->buildConversationOfTheRecordPieces(null, null, null);
        self::assertNull($recordConversationAtAccountLevel->agentSpaceId);
        self::assertNull($recordConversationAtAccountLevel->agentSpace);

        $doneCriterionInputs = $aIConversationPurpose->buildDoneCriterionInputs($recordConversation, $taskPlanItems);
        self::assertSame($recordConversation, $doneCriterionInputs->conversation);
        self::assertSame($taskPlanItems, $doneCriterionInputs->taskPlanItems);
    }

    public function testTheScheduleRunVariantsReadTheReportChannelWithoutASpace(): void
    {
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->reportChannel = 'email';
        self::assertSame('EMAIL', (new ScheduleRunPurpose())->resolveVariants($conversation)->getValue(ScheduleRunPurpose::DIMENSION_REPORT_CHANNEL));
    }

    public function testNoWidgetAppliesAnOwnerEditByDefault(): void
    {
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->agentSpaceId = 12;
        $conversation->messages = new AIConversationMessages();
        self::assertNull(ConversationWidget::applyOwnerEditAtTurnOpen($conversation));
    }

    protected function buildStoredAgentSpace(int $id): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        return $agentSpace;
    }
}
