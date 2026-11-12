<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationResultSlice;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The results of the children of a `delegate` call stored without a sub-agent record are one value object per child:
 * the stored map is read into them and written back with the stored values, and the message's readers read them.
 */
class DelegationResultSliceTest extends UnitTestCase
{
    /** The map of a call whose children ran in their own workers: keyed by child conversation id, an older slice without a cost. */
    protected const array STORED_MAP_KEYED_BY_CHILD_ID = [
        '812' => ['task' => 'Read the services page.', 'verdict' => 'The page lists 6 services.', 'costInUSD' => 0.0123],
        '811' => ['task' => 'Read the contact page.', 'verdict' => ''],
    ];

    /** The list of a call whose children ran inside the parent's turn: keyed by spawn index. */
    protected const array STORED_LIST_KEYED_BY_SPAWN_INDEX = [
        ['task' => 'First task.', 'verdict' => 'first', 'costInUSD' => 0.0],
        ['task' => 'Second task.', 'verdict' => 'second', 'costInUSD' => 0.5],
    ];

    public function testAStoredMapIsReadIntoOneSlicePerChildAndWrittenBackWithTheStoredValues(): void
    {
        foreach ([self::STORED_MAP_KEYED_BY_CHILD_ID, self::STORED_LIST_KEYED_BY_SPAWN_INDEX] as $storedSlices) {
            $delegationResults = new DelegationResults();
            $delegationResults->mapFromRepository($storedSlices);

            self::assertTrue($delegationResults->isLegacyMap());
            self::assertContainsOnlyInstancesOf(DelegationResultSlice::class, $delegationResults->results);
            self::assertSame(array_keys($storedSlices), array_keys($delegationResults->results));
            self::assertSame(
                json_encode($storedSlices, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                json_encode($delegationResults->mapToRepository(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            );
        }
    }

    public function testASliceStoredBeforeTheCostExistedCarriesNoCost(): void
    {
        $delegationResults = new DelegationResults();
        $delegationResults->mapFromRepository(self::STORED_MAP_KEYED_BY_CHILD_ID);

        self::assertSame(0.0123, $delegationResults->results['812']->costInUSD);
        self::assertNull($delegationResults->results['811']->costInUSD);
        self::assertArrayNotHasKey('costInUSD', $delegationResults->results['811']->toStoredSlice());
    }

    public function testAStoredValueThatIsNoSliceIsNotRead(): void
    {
        $delegationResults = new DelegationResults();
        $delegationResults->mapFromRepository(['812' => ['task' => 'Read the services page.', 'verdict' => 'done'], '813' => null, '814' => 'text']);

        self::assertSame(['812'], array_map('strval', array_keys($delegationResults->results)));
    }

    public function testABlankVerdictIsNoResult(): void
    {
        self::assertNull(DelegationResultSlice::forTaskAndVerdict('Read the contact page.', "  \n")->getVerdictUnlessBlank());
        self::assertSame('done', DelegationResultSlice::forTaskAndVerdict('Read the contact page.', 'done')->getVerdictUnlessBlank());
    }

    public function testTheDelegateCallReadsItsChildrensSlices(): void
    {
        $delegateCallMessage = new AIConversationMessage();
        $delegateCallMessage->delegationResults = new DelegationResults();
        $delegateCallMessage->delegationResults->mapFromRepository(self::STORED_MAP_KEYED_BY_CHILD_ID);

        self::assertSame(['The page lists 6 services.'], $delegateCallMessage->getDelegationVerdictTexts());
        self::assertSame(2, $delegateCallMessage->getDelegationChildVerdictCount());
        self::assertSame(
            "Sub-agent 1 — task: Read the contact page.\n→ (sub-agent produced no verdict)\n\n"
            . "Sub-agent 2 — task: Read the services page.\n→ The page lists 6 services.",
            $delegateCallMessage->getDelegationResult()
        );
    }
}
