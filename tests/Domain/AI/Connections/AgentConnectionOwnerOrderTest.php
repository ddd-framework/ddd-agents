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
use DDD\Domain\AI\Entities\Conversations\Widgets\MailDraftPreviewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Services\Connections\Adapters\Mime\AgentConnectionMimeMessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The evidence of a shown mail for the consent step: only the agent's own text that showed the exact mail (recipients,
 * subject, body) counts, and the consent dialogue then carries it before the owner's order. The decision itself stays
 * with the consent model (an unrelated message stays no).
 */
class AgentConnectionOwnerOrderTest extends TestCase
{
    protected const string BODY = "Hallo Anna,\n\nder Termin morgen um 10 Uhr passt.\n\nViele Grüße";

    public function testTheAgentsOwnTextThatShowedTheWholeMailIsTheEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org', 'team@example.org'], self::BODY, 'senden');
        self::assertSame(1, $this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation), 'one shown mail covers every recipient');
    }

    public function testAMailChangedAfterShowingHasNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY . "\nPS: bring Kaffee mit", 'senden');
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation));
    }

    public function testARecipientThatWasNotShownHasNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org', 'info@example.org'], self::BODY, 'senden');
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation));
    }

    public function testAMailShownOnlyInAWidgetOrToolRowIsNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'senden', shownByTheAgent: false);
        self::assertNull($this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation), 'text from outside may sit in a widget or tool row');
    }

    public function testTheConsentDialogueCarriesTheShownMailBeforeTheOwnersMessage(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'jetzt senden');
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
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'senden', shownByTheAgent: false);
        $messages = $agentConnectionOperation->callProvenance->conversationMessages;
        $request = OwnerConsentRequest::forAction('sendMail', 'send through Test', 'Send mail', 'It reaches anna@example.org.', 'hash');
        $request->shownContentMessageIndex = 1;
        $dialogue = $this->getOwnerConsentsService()->buildOwnerConsentDialogue($messages, 2, null);
        self::assertFalse($this->getOwnerConsentsService()->addShownContentLine($dialogue, $messages, $request), 'a tool or widget row gives no evidence even when named');
    }

    public function testTheMailToolsPreviewForExactlyThisMailIsTheEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'snde', shownByTheAgent: false);
        $messages = $agentConnectionOperation->callProvenance->conversationMessages;
        $previewRow = $this->buildPreviewRow(2, $agentConnectionOperation, MailDraftPreviewConversationWidget::STATUS_DRAFT, ConversationWidgetOrigin::tool('sendMail'));
        $this->replaceRow($messages, $previewRow);
        self::assertSame(2, $this->getOperationsService()->findShownContentMessageIndex($agentConnectionOperation), 'the tool-written DRAFT preview of this content');

        $request = OwnerConsentRequest::forAction('sendMail', 'send through Test', 'Send mail', 'It reaches anna@example.org.', $agentConnectionOperation->getContentHash());
        $request->shownContentMessageIndex = 2;
        $dialogue = $this->getOwnerConsentsService()->buildOwnerConsentDialogue($messages, 2, null);
        self::assertTrue($this->getOwnerConsentsService()->addShownContentLine($dialogue, $messages, $request));
        $previewLineText = '';
        foreach ($dialogue->getElements() as $dialogueLine) {
            if ($dialogueLine->messageIndex === 2) {
                $previewLineText = $dialogueLine->text;
            }
        }
        self::assertStringContainsString('To: anna@example.org', $previewLineText);
        self::assertStringContainsString('send button', $previewLineText);
    }

    public function testAPreviewTheModelTypedOrOfOtherContentIsNoEvidence(): void
    {
        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'senden', shownByTheAgent: false);
        $messages = $agentConnectionOperation->callProvenance->conversationMessages;
        $typedRow = $this->buildPreviewRow(2, $agentConnectionOperation, MailDraftPreviewConversationWidget::STATUS_DRAFT, ConversationWidgetOrigin::tool('sendMail'));
        $typedRow->messageType = AIConversationMessage::MESSAGE_TEXT;
        $typedRow->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $this->replaceRow($messages, $typedRow);
        self::assertNull(AgentConnectionOperationsService::findShownMailPreviewIndex($messages, $agentConnectionOperation->getContentHash(), 3), 'a block in the model\'s own text reads as the model\'s');

        $sentRow = $this->buildPreviewRow(2, $agentConnectionOperation, MailDraftPreviewConversationWidget::STATUS_SENT, ConversationWidgetOrigin::tool('sendMail'));
        $this->replaceRow($messages, $sentRow);
        self::assertNull(AgentConnectionOperationsService::findShownMailPreviewIndex($messages, $agentConnectionOperation->getContentHash(), 3), 'a SENT preview asks nothing');

        $draftRow = $this->buildPreviewRow(2, $agentConnectionOperation, MailDraftPreviewConversationWidget::STATUS_DRAFT, ConversationWidgetOrigin::tool('sendMail'));
        $this->replaceRow($messages, $draftRow);
        self::assertNull(AgentConnectionOperationsService::findShownMailPreviewIndex($messages, 'another-hash', 3), 'another mail\'s preview');
    }

    public function testAddressesTheOwnerWroteAreCoveredByTheRuleAndOthersAreNot(): void
    {
        $agentConnectionOperation = $this->buildSend(['marius@example.org'], self::BODY, 'Sende die Übersicht an Marius@Example.org.');
        self::assertSame(['marius@example.org'], $this->getOperationsService()->findAddressesNamedByOwner($agentConnectionOperation));

        $agentConnectionOperation = $this->buildSend(['anna@example.org'], self::BODY, 'senden');
        self::assertSame([], $this->getOperationsService()->findAddressesNamedByOwner($agentConnectionOperation), 'an address only the agent wrote is not the owner\'s');
    }

    public function testTheMimeMessageCarriesThePlainBodyAndItsMarkdownAsHtml(): void
    {
        $mailDraft = AgentConnectionMailDraft::newInstance();
        $mailDraft->to = ['anna@example.org'];
        $mailDraft->subject = 'Kampagnen';
        $mailDraft->bodyText = "Hallo\n\n| # | Name |\n|---|---|\n| 1 | <b>x</b> |";
        $rawMessage = AgentConnectionMimeMessageBuilder::build($mailDraft, 'owner@example.org');
        self::assertStringContainsString('Content-Type: multipart/alternative', $rawMessage);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $rawMessage);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $rawMessage);
        $htmlBody = AgentConnectionMimeMessageBuilder::buildHtmlBody($mailDraft->bodyText);
        self::assertStringContainsString('<table', $htmlBody);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $htmlBody, 'safe mode: no raw HTML from model text');
    }

    public function testTheHtmlBodyCarriesNoImageOfAnyOrigin(): void
    {
        $htmlBody = AgentConnectionMimeMessageBuilder::buildHtmlBody("Hallo ![Logo](https://tracker.example.org/p.png?id=1)\n\n<img src=\"https://tracker.example.org/q.png\">\n\n[Bericht](https://example.org/bericht)");
        self::assertStringNotContainsString('<img', $htmlBody, 'a remote image is a tracking pixel');
        self::assertStringContainsString('Logo', $htmlBody, 'the image\'s alt text stays');
        self::assertStringContainsString('<a href="https://example.org/bericht">Bericht</a>', $htmlBody, 'links stay');
    }

    protected function buildPreviewRow(int $index, AgentConnectionOperation $agentConnectionOperation, string $status, ConversationWidgetOrigin $conversationWidgetOrigin): AIConversationMessage
    {
        $mailDraftPreviewWidget = MailDraftPreviewConversationWidget::forMailDraft(
            $agentConnectionOperation->stepArguments->mailDraft,
            3,
            'owner@example.org',
            $agentConnectionOperation->getContentHash(),
            $status
        );
        $previewRow = $this->buildRow($index, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, $mailDraftPreviewWidget->buildWidgetRowEmittedBy($conversationWidgetOrigin));
        $previewRow->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        return $previewRow;
    }

    protected function replaceRow(AIConversationMessages $messages, AIConversationMessage $replacementRow): void
    {
        foreach ($messages->getElements() as $row) {
            if ($row->indexInConversation === $replacementRow->indexInConversation) {
                $messages->remove($row);
            }
        }
        $messages->add($replacementRow);
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
        $requestRow = $this->buildRow(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Schreib Anna, dass der Termin passt.');
        $messages->add($requestRow);
        $shownRow = $this->buildRow(1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT,
            "Hier die Mail:\n\n**An:** anna@example.org, team@example.org\n**Betreff:** Termin morgen\n\n" . self::BODY . "\n\nSoll ich sie so senden?");
        if (!$shownByTheAgent) {
            $shownRow->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        }
        $messages->add($shownRow);
        $refusalRow = $this->buildRow(2, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Die Verbindung darf noch nicht senden.');
        $messages->add($refusalRow);
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
