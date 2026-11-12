<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * Covers (1) the AWAITING-owner state of a widget the owner answers
 * (`doneRequiresWidgetAnswered`; the former non-blocking offer flag is gone): the sweep stamps the rendered
 * widget, the item leaves `← next` and the owed-work list but stays open, the judge line
 * carries the render provenance; (2) `doneRequiresToolCall` alternatives ("a|b"); (3) the same-turn
 * owner-visible answer must come AFTER the item's own evidence call; (4) the mid-turn owner inbound finder.
 */
class TaskPlanNonBlockingOfferTest extends UnitTestCase
{
    protected function item(string $text): TaskPlanItem
    {
        $item = new TaskPlanItem();
        $item->text = $text;
        $item->status = TaskPlanItem::STATUS_OPEN;
        return $item;
    }

    protected function message(int $index, string $type, string $text, ?string $role, ?string $authorType = null, ?string $signalSource = null): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = $type;
        $message->message = $text;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    protected function userMessage(int $index, string $text = 'hallo'): AIConversationMessage
    {
        return $this->message($index, AIConversationMessage::MESSAGE_TEXT, $text, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
    }

    protected function assistantText(int $index, string $text): AIConversationMessage
    {
        return $this->message($index, AIConversationMessage::MESSAGE_TEXT, $text, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT);
    }

    protected function completedCall(int $index, string $toolName, array $arguments = []): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_AGENT;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->markCompleted();
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @return string[] */
    protected function tailLines(TaskPlanItems $items): array
    {
        return $items->buildTailHintLines();
    }

    protected function messageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', $messages);
        self::setPropertyValue($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }

    public function testARenderedOwnerAnsweredWidgetIsAwaitingTheOwnerButStillOpen(): void
    {
        $offer = $this->item('Google connect offer');
        $offer->doneRequiresWidgetAnswered = true;
        $offer->doneRequiresWidget = 'ChannelConnections';
        $work = $this->item('Profile draft');
        $items = new TaskPlanItems();
        $items->add($offer);
        $items->add($work);

        $noWidgetYet = $this->messageSet([$this->userMessage(1), $this->assistantText(2, 'Welcome')]);
        self::assertSame(0, $items->stampRenderedNonBlockingOffers($noWidgetYet));
        self::assertFalse($offer->isAwaitingOwnerOffer());
        self::assertStringContainsString('← next', $this->tailLines($items)[0]);

        $withWidget = $this->messageSet([$this->userMessage(1), $this->assistantText(2, "Connect here:\n```widget\n{\"type\": \"ChannelConnections\", \"shopId\": 1}\n```")]);
        self::assertSame(1, $items->stampRenderedNonBlockingOffers($withWidget));
        self::assertSame(2, $offer->offerRenderedAtMessageIndex);
        self::assertSame(0, $items->stampRenderedNonBlockingOffers($withWidget), 'idempotent');
        self::assertTrue($offer->isAwaitingOwnerOffer());
        self::assertTrue($offer->isOpen(), 'still open — the owner has not answered');
        self::assertFalse($offer->isOwedOpen());
        self::assertTrue($items->hasOpenItems());
        self::assertTrue($items->hasOwedOpenItems(), 'the profile draft is still owed');
        self::assertSame(['Profile draft'], $items->getOpenItemPathTexts(), 'the awaiting offer is not remaining work');

        $tailLines = $this->tailLines($items);
        self::assertStringContainsString('[⏳ awaiting the owner\'s answer on the widget', $tailLines[0]);
        self::assertStringNotContainsString('← next', $tailLines[0]);
        self::assertStringContainsString('← next', $tailLines[1]);
        self::assertStringContainsString('(awaiting the owner\'s answer, widget rendered @2)', $offer->toJudgeLine('1'));
        // the evidence predicate ignores an awaiting offer
        self::assertFalse($items->hasOpenItemsRequiringDoneEvidence(), 'the awaiting widget owes no evidence; the draft has no gate');
        // the legacy Directive spelling is stamped like the canonical name (one matcher with the gates)
        $legacyOffer = $this->item('Social connect');
        $legacyOffer->doneRequiresWidgetAnswered = true;
        $legacyOffer->doneRequiresWidget = 'ChannelConnections';
        $legacyItems = new TaskPlanItems();
        $legacyItems->add($legacyOffer);
        $legacyWidget = $this->messageSet([$this->userMessage(1), $this->assistantText(2, "```widget\n{\"type\": \"ChannelConnectionsDirective\"}\n```")]);
        self::assertSame(1, $legacyItems->stampRenderedNonBlockingOffers($legacyWidget));

        // the fields round-trip through the repository slice and the seed
        $seeded = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Offer', 'doneRequiresWidgetAnswered' => true, 'doneRequiresWidget' => 'ChannelConnections']])->getElements()[0];
        self::assertTrue($seeded->doneRequiresWidgetAnswered);
        self::assertTrue($seeded->carriesEvidenceGate());
        $seeded->offerRenderedAtMessageIndex = 7;
        $reloaded = TaskPlanItem::fromRepositoryFields($seeded->toRepositoryFields());
        self::assertTrue($reloaded->doneRequiresWidgetAnswered);
        self::assertSame(7, $reloaded->offerRenderedAtMessageIndex);
    }

    public function testADoneGateAcceptsAnyOfItsAlternativeTools(): void
    {
        $leaf = $this->item('Competitors');
        $leaf->doneRequiresToolCall = 'addCompetitors|updateCompetitors';
        self::assertSame(['addCompetitors', 'updateCompetitors'], $leaf->getDoneRequiresToolCallAlternatives());
        $taskPlan = new TaskPlan();
        $taskPlan->items = new TaskPlanItems();
        $taskPlan->items->add($leaf);
        $tick = ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'list saved']]];

        $none = SetTaskStatusTestTick::apply($tick, $taskPlan, 1, $this->messageSet([$this->userMessage(1)]));
        self::assertSame(0, $none['updatedCount']);
        self::assertStringContainsString('`addCompetitors` or `updateCompetitors` call; the platform ticks it then', implode(' ', $none['feedback']));

        $curated = SetTaskStatusTestTick::apply($tick, $taskPlan, 1, $this->messageSet([$this->userMessage(1), $this->completedCall(2, 'updateCompetitors')]));
        self::assertSame(1, $curated['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $leaf->status);

        $items = new TaskPlanItems();
        $fresh = $this->item('Competitors');
        $fresh->doneRequiresToolCall = 'addCompetitors|updateCompetitors';
        $items->add($fresh);
        self::assertSame('addCompetitors', $items->getNextOwedDoneGateToolName($this->messageSet([$this->userMessage(1)])), 'the first alternative is the one to force');
        self::assertNull($items->getNextOwedDoneGateToolName($this->messageSet([$this->userMessage(1), $this->completedCall(2, 'addCompetitors')])));
    }

    public function testTheSameTurnAnswerMustFollowTheItemsOwnEvidenceCall(): void
    {
        $leaf = $this->item('Keywords presented');
        $leaf->doneRequiresToolCall = 'addKeywords';
        $leaf->doneRequiresOwnerVisibleAnswerInSameTurn = true;
        $taskPlan = new TaskPlan();
        $taskPlan->items = new TaskPlanItems();
        $taskPlan->items->add($leaf);
        $tick = ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'keywords shown']]];

        // an owner-visible text BEFORE the evidence call (the connect widget's message) is not this item's delivery
        $textBeforeEvidence = $this->messageSet([$this->userMessage(1), $this->assistantText(2, 'Connect Google here (widget).'), $this->completedCall(3, 'addKeywords')]);
        $rejected = SetTaskStatusTestTick::apply($tick, $taskPlan, 1, $textBeforeEvidence);
        self::assertSame(0, $rejected['updatedCount']);
        self::assertStringContainsString('not applied', implode(' ', $rejected['feedback']));

        $textAfterEvidence = $this->messageSet([$this->userMessage(1), $this->assistantText(2, 'Connect Google here (widget).'), $this->completedCall(3, 'addKeywords'), $this->assistantText(4, 'Your keywords: a, b, c.')]);
        $accepted = SetTaskStatusTestTick::apply($tick, $taskPlan, 1, $textAfterEvidence);
        self::assertSame(1, $accepted['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $leaf->status);
    }

    public function testAMidTurnOwnerInboundIsFoundOnlyBetweenThePreTurnMarkAndTheTrailingRow(): void
    {
        $preTurnMark = 3;
        $messages = $this->messageSet([
            $this->userMessage(3, 'initiator'),
            $this->completedCall(4, 'getProfileSummary'),
            $this->message(5, AIConversationMessage::MESSAGE_SIGNAL, 'User connected Google', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
            $this->assistantText(6, 'Next question: …'),
        ]);
        self::assertSame([5], $messages->findOwnerInboundArrivedMidTurnIndexes($preTurnMark));
        $twoInbounds = $this->messageSet([
            $this->userMessage(3, 'initiator'),
            $this->userMessage(4, 'and one more thing'),
            $this->completedCall(5, 'getProfileSummary'),
            $this->message(6, AIConversationMessage::MESSAGE_SIGNAL, 'User connected Google', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
            $this->assistantText(7, 'Next question: …'),
        ]);
        self::assertSame([4, 6], $twoInbounds->findOwnerInboundArrivedMidTurnIndexes($preTurnMark), 'every mid-turn owner row, none orphaned');

        $trailingOnly = $this->messageSet([$this->userMessage(3), $this->assistantText(4, 'Q?'), $this->userMessage(5, 'answer')]);
        self::assertSame([], $trailingOnly->findOwnerInboundArrivedMidTurnIndexes($preTurnMark), 'a trailing owner row is owed by the normal walk');

        $loopSignalOnly = $this->messageSet([$this->userMessage(3), $this->message(4, AIConversationMessage::MESSAGE_SIGNAL, 'nudge', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE), $this->assistantText(5, 'ok')]);
        self::assertSame([], $loopSignalOnly->findOwnerInboundArrivedMidTurnIndexes($preTurnMark), 'a loop signal is not the owner');
    }
}
