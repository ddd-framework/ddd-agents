<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeDoneCriteriaPurpose;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeReadsThePlanDoneCriterion;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeRecordDoneCriterion;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeSecondRecordDoneCriterion;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeThrowingDoneCriterion;
use DDD\Tests\Agents\Support\UnitTestCase;
use LogicException;
use stdClass;

/**
 * The registry of a purpose's done criteria, the lazy walk over it
 * ({@see TaskPlanItems::applyPreResolutions()}) and the base purpose's generic hooks over a purpose that only registers
 * classes.
 */
class TaskPlanDoneCriteriaTest extends UnitTestCase
{
    protected static function registry(): TaskPlanDoneCriteria
    {
        return TaskPlanDoneCriteria::fromClasses(ProbeRecordDoneCriterion::class, ProbeReadsThePlanDoneCriterion::class, ProbeThrowingDoneCriterion::class);
    }

    public function testFromClassesKeysEveryCriterionByItsKeyInOrder(): void
    {
        $taskPlanDoneCriteria = self::registry();
        self::assertSame(['probe.record', 'probe.readsThePlan', 'probe.throwing'], $taskPlanDoneCriteria->getKeys());
        self::assertInstanceOf(ProbeRecordDoneCriterion::class, $taskPlanDoneCriteria->forKey('probe.record'));
        self::assertInstanceOf(ProbeReadsThePlanDoneCriterion::class, $taskPlanDoneCriteria->forKey('probe.readsThePlan'));
        self::assertNull($taskPlanDoneCriteria->forKey('listings.syncOffer'), 'a key without a class');
        self::assertNull($taskPlanDoneCriteria->forKey(''));
        self::assertSame([], TaskPlanDoneCriteria::fromClasses()->getKeys());
    }

    public function testADuplicateKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'probe.record' is registered twice");
        TaskPlanDoneCriteria::fromClasses(ProbeRecordDoneCriterion::class, ProbeSecondRecordDoneCriterion::class);
    }

    public function testAClassThatIsNoCriterionThrows(): void
    {
        $this->expectException(LogicException::class);
        TaskPlanDoneCriteria::fromClasses(stdClass::class);
    }

    public function testResolveKeyAnswersTheVerdictNullForAKeyWithoutAClassAndNullForACriterionThatThrows(): void
    {
        $taskPlanDoneCriteria = self::registry();
        $withMessages = TaskPlanDoneCriterionInputs::forConversation(AIConversation::newInstance(), new AIConversationMessages(), null);
        self::assertSame('already satisfied: probe.record', $taskPlanDoneCriteria->resolveKey('probe.record', $withMessages)?->statusNote);
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlanDoneCriteria->resolveKey('probe.record', $withMessages)?->status);
        self::assertTrue($taskPlanDoneCriteria->resolveKey('probe.record', new TaskPlanDoneCriterionInputs())?->isStillOpen());
        self::assertNull($taskPlanDoneCriteria->resolveKey('stored.keyWithoutAClass', $withMessages), 'no class = no gate');
        self::assertNull($taskPlanDoneCriteria->resolveKey('probe.throwing', $withMessages), 'a throw answers null for that key');
    }

    public function testGetKeysChangedByToolReadsTheCriteriaThenTheKnownInertToolsThenNull(): void
    {
        $taskPlanDoneCriteria = self::registry();
        self::assertSame(['probe.record', 'probe.readsThePlan'], $taskPlanDoneCriteria->getKeysChangedByTool('importProbe', ['remember']));
        self::assertSame(['probe.record'], $taskPlanDoneCriteria->getKeysChangedByTool('updateProbeRecord', ['remember']));
        self::assertSame([], $taskPlanDoneCriteria->getKeysChangedByTool('remember', ['remember']), 'known, changes no criterion');
        self::assertNull($taskPlanDoneCriteria->getKeysChangedByTool('someToolNobodyMapped', ['remember']), 'unknown — every open criterion leaf');
    }

    public function testTheWalkEvaluatesEachKeyLazilyOverTheLivePlan(): void
    {
        // the plan-reading leaf comes AFTER the record leaf: when the walk reaches it, the record leaf is already done
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Record', 'doneCriterion' => 'probe.record'],
            ['text' => 'Reads the plan', 'doneCriterion' => 'probe.readsThePlan'],
            ['text' => 'Throws', 'doneCriterion' => 'probe.throwing'],
            ['text' => 'Stored legacy key', 'doneCriterion' => 'listings.syncOffer'],
            'A leaf without a criterion',
        ]);
        $conversationMessages = new AIConversationMessages();

        $resolvedLeafCount = $taskPlanItems->applyPreResolutions(
            self::registry(),
            TaskPlanDoneCriterionInputs::forConversation(AIConversation::newInstance(), $conversationMessages, $taskPlanItems),
            $conversationMessages
        );

        $itemElements = array_values($taskPlanItems->getElements());
        self::assertSame(2, $resolvedLeafCount);
        self::assertSame(TaskPlanItem::STATUS_DONE, $itemElements[0]->status);
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $itemElements[1]->status, 'read the record leaf as mutated earlier in the same walk');
        self::assertSame('the record leaf is done', $itemElements[1]->statusNote);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $itemElements[2]->status);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $itemElements[3]->status, 'a stored key without a class is no gate');
        self::assertNull($itemElements[3]->statusNote);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $itemElements[4]->status);
    }

    public function testTheBasePurposeHooksAreGenericOverTheRegisteredClasses(): void
    {
        $probeDoneCriteriaPurpose = new ProbeDoneCriteriaPurpose();
        self::assertSame(['probe.record', 'probe.readsThePlan', 'probe.throwing'], $probeDoneCriteriaPurpose->getDoneCriteria()->getKeys());
        self::assertSame(['probe.record', 'probe.readsThePlan'], $probeDoneCriteriaPurpose->criterionKeysChangedByTool('importProbe'));
        self::assertSame([], $probeDoneCriteriaPurpose->criterionKeysChangedByTool('addGoal'));
        self::assertNull($probeDoneCriteriaPurpose->criterionKeysChangedByTool('someToolNobodyMapped'));

        $agentSpace = new AgentSpace();
        $agentSpace->id = 1;
        $conversationMessages = new AIConversationMessages();
        self::assertSame('already satisfied: probe.record', $probeDoneCriteriaPurpose->resolveDoneCriterion('probe.record', $agentSpace, 1, $conversationMessages, new TaskPlanItems())?->statusNote);
        self::assertSame('already satisfied: probe.record', $probeDoneCriteriaPurpose->resolveDoneCriterion('probe.record', null, 1, $conversationMessages, new TaskPlanItems())?->statusNote, 'a criterion decides at account level too');
        self::assertNull($probeDoneCriteriaPurpose->resolveDoneCriterion('stored.keyWithoutAClass', $agentSpace, 1, $conversationMessages, new TaskPlanItems()));

        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Record', 'doneCriterion' => 'probe.record'],
            ['text' => 'Reads the plan', 'doneCriterion' => 'probe.readsThePlan'],
        ]);
        self::assertSame(1, $probeDoneCriteriaPurpose->preResolveSeedTaskPlanItems($taskPlanItems, $agentSpace, $conversationMessages, null, 1, ['probe.record']), 'the re-sweep names the keys');
        self::assertSame(1, $probeDoneCriteriaPurpose->preResolveSeedTaskPlanItems($taskPlanItems, $agentSpace, $conversationMessages));
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, array_values($taskPlanItems->getElements())[1]->status);
    }

    public function testThePlainBasePurposeDecidesNothing(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        self::assertSame([], $aIConversationPurpose->getDoneCriteria()->getKeys());
        self::assertNull($aIConversationPurpose->criterionKeysChangedByTool('updateProfile'), 'a purpose without criteria answers null, as before');
        $agentSpace = new AgentSpace();
        $agentSpace->id = 1;
        self::assertNull($aIConversationPurpose->resolveDoneCriterion('probe.record', $agentSpace));
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Record', 'doneCriterion' => 'probe.record']]);
        self::assertSame(0, $aIConversationPurpose->preResolveSeedTaskPlanItems($taskPlanItems, $agentSpace, new AIConversationMessages()));
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlanItems->first()?->status);
    }
}
