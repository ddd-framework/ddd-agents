<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantTransition;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The variant set's contracts: selector matching (`a|b`, pairs, undeclared dimensions), the trace tokens, the
 * working-state round trip (one `variants` slice) and the request-snapshot round trip the reconstruction relies on.
 */
class AIConversationVariantsTest extends UnitTestCase
{
    protected function set(): AIConversationVariants
    {
        $variants = new AIConversationVariants();
        $variants->put(AIConversationVariant::decided('modality', 'chat', AIConversationVariant::SOURCE_PURPOSE));
        $variants->put(AIConversationVariant::decided('identityPath', 'import', AIConversationVariant::SOURCE_OWNER, 8));
        $variants->put(AIConversationVariant::proposed('businessClass', 'walkIn', AIConversationVariant::SOURCE_RECORD, 8));
        $variants->put(AIConversationVariant::unknown('locality'));
        return $variants;
    }

    public function testSelectorsMatchValuesAlternativesAndPairsNeverUndeclaredDimensions(): void
    {
        $variants = $this->set();
        self::assertTrue($variants->matchesSelectors(['businessClass' => 'walkIn']));
        self::assertTrue($variants->matchesSelectors(['businessClass' => 'mobile|walkIn']));
        self::assertTrue($variants->matchesSelectors(['businessClass' => 'walkIn', 'modality' => 'chat']));
        self::assertFalse($variants->matchesSelectors(['businessClass' => 'walkIn', 'modality' => 'phone']));
        self::assertTrue($variants->matchesSelectors(['locality' => 'unknown']), 'the unknown value is selectable');
        self::assertFalse($variants->matchesSelectors(['addressPublished' => 'undecided']), 'an undeclared dimension never matches');
        self::assertSame(['modality' => 'chat', 'identityPath' => 'import', 'businessClass' => 'walkIn', 'locality' => 'unknown'], $variants->toSelectorMap());
    }

    public function testTheTraceTokensNameTheProvenanceAndTheModelReadsSelectorsAndTheUnconfirmedOnesApart(): void
    {
        $variants = $this->set();
        self::assertSame('modality=chat(purpose) identityPath=import(owner@8) businessClass=walkIn(proposed,record@8) locality=unknown(proposed,purpose)', $variants->toTraceLine());
        self::assertSame('modality=chat · identityPath=import · businessClass=walkIn · locality=unknown', $variants->toSelectorLine());
        self::assertSame(
            'businessClass=walkIn is proposed from the record, not yet confirmed by the owner; locality=unknown is not yet known',
            $variants->toUnconfirmedProvenanceLine()
        );

        $confirmed = new AIConversationVariants();
        $confirmed->put(AIConversationVariant::decided('identityPath', 'import', AIConversationVariant::SOURCE_OWNER, 8));
        self::assertNull($confirmed->toUnconfirmedProvenanceLine(), 'nothing to say when every dimension is confirmed');
    }

    public function testPutReplacesTheSameDimensionAndTransitionsNameWhatChanged(): void
    {
        $before = $this->set();
        $after = $this->set();
        $after->put(AIConversationVariant::decided('businessClass', 'mobile', AIConversationVariant::SOURCE_OWNER, 14));
        self::assertCount(4, $after->getElements());
        $transitions = $after->transitionsSince($before);
        self::assertContainsOnlyInstancesOf(AIConversationVariantTransition::class, $transitions);
        self::assertSame(
            [['dimension' => 'businessClass', 'from' => 'businessClass=walkIn(proposed,record@8)', 'to' => 'businessClass=mobile(owner@14)']],
            array_map(static fn (AIConversationVariantTransition $transition): array => $transition->toTraceArray(), $transitions)
        );
        self::assertSame('none', $after->transitionsSince(null)[0]->from, 'a dimension without an earlier value comes from none');
        self::assertCount(4, $after->transitionsSince(null), 'against nothing every dimension is a transition');
    }

    public function testTheWorkingStateRoundTripsTheVariantsSlice(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->variants = $this->set();
        $repository = $agentWorkingState->mapToRepository();
        self::assertCount(4, $repository['variants']);
        self::assertSame(['dimension' => 'businessClass', 'value' => 'walkIn', 'source' => 'record', 'decided' => false, 'evidenceMessageIndex' => 8, 'resolvedAtMessageIndex' => null], $repository['variants'][2]);
        $reloaded = new AgentWorkingState();
        $reloaded->mapFromRepository(json_decode((string)json_encode($repository), true));
        self::assertSame($this->set()->toTraceLine(), $reloaded->variants?->toTraceLine());
        $empty = new AgentWorkingState();
        $empty->mapFromRepository(['variants' => []]);
        self::assertNull($empty->variants);
    }

    public function testTheSnapshotRoundTripKeepsValueDecisionSourceAndEvidence(): void
    {
        $snapshot = $this->set()->toSnapshotArray();
        self::assertSame(['modality' => 'chat(purpose)', 'identityPath' => 'import(owner@8)', 'businessClass' => 'walkIn(proposed,record@8)', 'locality' => 'unknown(proposed,purpose)'], $snapshot);
        $rebuilt = AIConversationVariants::fromSnapshotArray($snapshot);
        self::assertSame($this->set()->toTraceLine(), $rebuilt->toTraceLine());
        self::assertTrue($rebuilt->matchesSelectors(['businessClass' => 'walkIn']));
        self::assertFalse($rebuilt->isDecided('businessClass'));
        self::assertSame(8, $rebuilt->get('identityPath')?->evidenceMessageIndex);
    }
}
