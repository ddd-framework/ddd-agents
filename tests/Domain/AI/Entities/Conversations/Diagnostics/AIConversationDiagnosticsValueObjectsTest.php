<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityAdmittedPreload;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityRecords;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityReport;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityViolation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosis;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosisCoverNode;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosisStateRow;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosisTailRow;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The two admin diagnoses of a conversation carry their rows as value objects: the integrity report its violations
 * and admitted preloads, the resume diagnosis its state rows, cover nodes and tail rows. The printed text of the
 * resume diagnosis is the text it had when the rows were arrays.
 */
class AIConversationDiagnosticsValueObjectsTest extends UnitTestCase
{
    public function testTheIntegrityReportListsItsViolationsAndAdmittedPreloadsAsValueObjects(): void
    {
        $messageRows = [
            ['indexInConversation' => 0, 'role' => AIConversationMessage::ROLE_USER, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'truncated' => 0, 'loadedCapabilities' => '[{"name":"Skill.One","type":"SKILL"}]'],
            ['indexInConversation' => 1, 'role' => AIConversationMessage::ROLE_ASSISTANT, 'messageType' => AIConversationMessage::MESSAGE_TEXT, 'truncated' => 0],
        ];
        $integrityRecords = AIConversationIntegrityRecords::forPreloadDecisionsAndTurnInitiatorIndexes([
            AIConversationIntegrityPreloadDecision::forTurn(0, 1, true, ['Skill.One', 'Skill.Two']),
        ], [0]);

        $report = AIConversationIntegrityReport::buildFromRecords($messageRows, $integrityRecords, AIConversationRunState::IDLE);

        self::assertContainsOnlyInstancesOf(AIConversationIntegrityAdmittedPreload::class, $report->admittedPreloads);
        self::assertSame(['Skill.One', 'Skill.Two'], array_column($report->admittedPreloads, 'skillName'));
        self::assertSame(1, $report->countAdmittedPreloadsOnTheirAnchor());
        self::assertSame(0, $report->countAdmittedPreloadsLoadedAgain());
        self::assertContainsOnlyInstancesOf(AIConversationIntegrityViolation::class, $report->violations);
        self::assertSame([AIConversationIntegrityReport::CHECK_PRELOAD_ON_ANCHOR], $report->getViolatedChecks());
        self::assertStringContainsString('admitted Skill.Two is on no anchor row', $report->violations[0]->detail);
        self::assertTrue($report->hasViolations());
    }

    public function testATailRowCutsTheMessageTextToOneShortLine(): void
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = 12;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = "The  first line\nand a second line that is long enough to be cut";

        $tailRow = AIConversationResumeDiagnosisTailRow::forMessage($message);

        self::assertSame(['12', AIConversationMessage::ROLE_ASSISTANT . '/' . AIConversationMessage::MESSAGE_TEXT, 'The first line and a second lin…'], $tailRow->getPrintedValues());
        self::assertSame(32, mb_strlen($tailRow->messagePreview));
    }

    public function testACoverNodePrintsADashForAValueTheCompactionNodeDoesNotCarry(): void
    {
        $compactionNode = new AIConversationCompactionNode();
        $compactionNode->level = 1;
        $compactionNode->coveredFromMessageIndex = 0;
        $compactionNode->coveredToMessageIndex = 41;
        $compactionNode->coveredMessageCount = 42;
        $compactionNode->coveredTokens = 18000;
        $compactionNode->summaryTokens = null;

        $coverNode = AIConversationResumeDiagnosisCoverNode::forCompactionNode($compactionNode);

        self::assertNull($coverNode->summaryTokens);
        self::assertSame(['1', '0', '41', '42', '18000', '—'], $coverNode->getPrintedValues());
    }

    public function testTheResumeDiagnosisPrintsItsRowsAsBefore(): void
    {
        $compactionNode = new AIConversationCompactionNode();
        $compactionNode->level = 0;
        $compactionNode->coveredFromMessageIndex = 0;
        $compactionNode->coveredToMessageIndex = 9;
        $compactionNode->coveredMessageCount = 10;
        $compactionNode->coveredTokens = 4000;
        $compactionNode->summaryTokens = 300;
        $message = new AIConversationMessage();
        $message->indexInConversation = 10;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Is it done?';

        $diagnosis = new AIConversationResumeDiagnosis();
        $diagnosis->aiConversationId = 77;
        $diagnosis->stateRows = [
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('runState', 'IDLE'),
            AIConversationResumeDiagnosisStateRow::forLabelAndValue('resumeAttempts', '0'),
        ];
        $diagnosis->coverNodes = [AIConversationResumeDiagnosisCoverNode::forCompactionNode($compactionNode)];
        $diagnosis->coveredUpToIndex = 9;
        $diagnosis->fullTailMessageCount = 11;
        $diagnosis->deriveTailMessageCount = 1;
        $diagnosis->deriveTailRows = [AIConversationResumeDiagnosisTailRow::forMessage($message)];
        $diagnosis->hasUnansweredInboundOnDeriveTail = true;
        $diagnosis->hasUnansweredInboundOnFullTail = true;

        self::assertSame(implode("\n", [
            '# Resume diagnosis of conversation 77',
            '',
            '- runState: IDLE',
            '- resumeAttempts: 0',
            '',
            'Summary cover reaches index 9 (1 nodes); the derive tail is the raw messages past it.',
            '- cover node level 0: messages 0–9 (10 messages, 4000 source tokens → 300 summary tokens)',
            'Full tail: 11 messages; derive tail: 1 messages.',
            '- unanswered inbound message on the DERIVE tail: yes',
            '- unanswered inbound message on the FULL tail: yes',
            '- oldest settled-unfolded forked batch: — (none)',
            '- any unsettled forked batch: no',
            '',
            'Verdict: WOULD DRIVE a turn (a trigger is present on the derive window).',
            '',
            'Newest rows of the derive tail (index · role/type · preview):',
            '- 10 · ' . AIConversationMessage::ROLE_USER . '/' . AIConversationMessage::MESSAGE_TEXT . ' · Is it done?',
        ]), $diagnosis->toLlmMarkdown());
    }
}
