<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

/**
 * The evidence of a shown mail for the consent step: only the agent's own text that showed the exact mail (recipients,
 * subject, body) counts, and the consent dialogue then carries it before the owner's order. The decision itself stays
 * with the consent model (an unrelated message stays no).
 */
class AgentConnectionOwnerOrderTest extends TestCase
{
    protected const string BODY = "Hallo Marius,\n\nder Termin morgen um 10 Uhr passt.\n\nViele Grüße";

    public function testTheAgentsOwnTextThatShowedTheWholeMailIsTheEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net', 'm.gerdan@radbonus.com'], self::BODY, 'senden');
        self::assertSame(1, $this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation), 'one shown mail covers every recipient');
    }

    public function testAMailChangedAfterShowingHasNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net'], self::BODY . "\nPS: bring Kaffee mit", 'senden');
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation));
    }

    public function testARecipientThatWasNotShownHasNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net', 'info@example.org'], self::BODY, 'senden');
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation));
    }

    public function testAMailShownOnlyInAWidgetOrToolRowIsNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net'], self::BODY, 'senden', shownByTheAgent: false);
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation), 'text from outside may sit in a widget or tool row');
    }

    public function testTheConsentDialogueCarriesTheShownMailBeforeTheOwnersMessage(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net'], self::BODY, 'jetzt senden');
        $messages = $agentConnectionOperation->callProvenance->conversationMessages;
        $request = OwnerConsentRequest::forAction('sendMail', 'send through Test', 'Send mail', 'the mail', 'hash');
        $request->shownContentMessageIndex = 1;
        $ownerConsentsService = $this->getOwnerConsentsService();
        $dialogue = $ownerConsentsService->buildOwnerConsentDialogue($messages, 2, null);
        self::assertNotContains(1, $this->lineIndexes($dialogue), 'without the evidence only the refusal text precedes the order');

        self::assertTrue($ownerConsentsService->addShownContentLine($dialogue, $messages, $request), 'the evidence answers whether the step was explained');
        self::assertSame([1, 2, 3], $this->lineIndexes($dialogue), 'the shown mail, the text before the order, the order: the decision model judges it in one pass');

        $widgetRequest = OwnerConsentRequest::forAction('sendMail', 'send through Test', 'Send mail', 'the mail', 'hash');
        $widgetRequest->shownContentMessageIndex = 2;
        $widgetDialogue = $ownerConsentsService->buildOwnerConsentDialogue($messages, 2, null);
        $ownerConsentsService->addShownContentLine($widgetDialogue, $messages, $widgetRequest);
        self::assertSame([2, 3], $this->lineIndexes($widgetDialogue), 'a line already in the dialogue is not added twice');
    }

    public function testAPreviewTheAgentWroteAsAWidgetIsReadAsItsText(): void
    {
        $readableText = OwnerConsentsService::composeReadableAssistantText("Hier die Vorschau.\n\n```widget\n" . json_encode(['type' => 'ComposedView', 'title' => 'E-Mail', 'view' => ['type' => 'Markdown', 'markdown' => "**An:** a@example.org\n\nText"]]) . "\n```");
        self::assertStringContainsString("**An:** a@example.org\n\nText", $readableText);
        self::assertStringNotContainsString('ComposedView', $readableText);
    }

    public function testAWidgetRowIsNeverTakenAsTheShownContent(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@gerdan.net'], self::BODY, 'senden', shownByTheAgent: false);
        $messages = $agentConnectionOperation->callProvenance->conversationMessages;
        $request = OwnerConsentRequest::forAction('sendMail', 'send through Test', 'Send mail', 'It reaches marius@gerdan.net.', 'hash');
        $request->shownContentMessageIndex = 1;
        $dialogue = $this->getOwnerConsentsService()->buildOwnerConsentDialogue($messages, 2, null);
        self::assertFalse($this->getOwnerConsentsService()->addShownContentLine($dialogue, $messages, $request), 'a tool or widget row gives no evidence even when named');
    }

    /** @return int[] */
    protected function lineIndexes(OwnerConsentDialogue $dialogue): array
    {
        $lineIndexes = [];
        foreach ($dialogue->getElements() as $dialogueLine) {
            $lineIndexes[] = $dialogueLine->messageIndex;
        }
        return $lineIndexes;
    }

    protected function getOwnerConsentsService(): OwnerConsentsService
    {
        /** @var OwnerConsentsService $ownerConsentsService */
        $ownerConsentsService = DDDService::instance()->getService(OwnerConsentsService::class);
        return $ownerConsentsService;
    }

    /** @param string[] $recipients */
    protected function buildSend(array $recipients, string $bodyText, string $ownerOrder, bool $shownByTheAgent = true): AgentConnectionOperation
    {
        $messages = new AIConversationMessages();
        $messages->add($this->buildRow(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Schreib Marius, dass der Termin passt.'));
        $shownRow = $this->buildRow(1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT,
            "Hier die Mail:\n\n**An:** marius@gerdan.net, m.gerdan@radbonus.com\n**Betreff:** Termin morgen\n\n" . self::BODY . "\n\nSoll ich sie so senden?");
        if (!$shownByTheAgent) {
            $shownRow->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        }
        $messages->add($shownRow);
        $messages->add($this->buildRow(2, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Die Verbindung darf noch nicht senden.'));
        $ownerRow = $this->buildRow(3, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, $ownerOrder);
        $messages->add($ownerRow);
        $callProvenance = new AgentToolCallProvenance(sourceUserMessage: $ownerRow, conversationId: 70, conversationMessages: $messages);
        $mailDraft = AgentConnectionMailDraft::newInstance();
        $mailDraft->to = $recipients;
        $mailDraft->subject = 'Termin morgen';
        $mailDraft->bodyText = $bodyText;
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 3;
        $agentConnectionOperation = AgentConnectionOperation::create($agentConnection, AgentConnectionPermission::CAPABILITY_MAIL, AgentConnectionAction::OPERATION_SEND, 'sendMail', 1, $callProvenance);
        $agentConnectionOperation->stepArguments->mailDraft = $mailDraft;
        return $agentConnectionOperation;
    }

    protected function buildRow(int $index, string $role, string $authorType, string $text): AIConversationMessage
    {
        $row = AIConversationMessage::newInstance();
        $row->indexInConversation = $index;
        $row->role = $role;
        $row->authorType = $authorType;
        $row->messageType = AIConversationMessage::MESSAGE_TEXT;
        $row->message = $text;
        return $row;
    }

    protected function getOperationsService(): AgentConnectionOperationsService
    {
        /** @var AgentConnectionOperationsService $agentConnectionOperationsService */
        $agentConnectionOperationsService = DDDService::instance()->getService(AgentConnectionOperationsService::class);
        return $agentConnectionOperationsService;
    }
}
