<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentConversationState;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateChangeDetection;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeltaTexts;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingInputs;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\AI\Entities\Skills\AISkillLoadRefusals;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * The sentences the module writes for the model and the owner name the application's things with the application's
 * words: the module ships general defaults and never the words of one application. An application that declares
 * "shop" reads "shop" in every one of these sentences; a sentence without a declared example carries none.
 */
class AgentApplicationWordingTest extends UnitTestCase
{
    protected const string SOURCE_FOLDER = __DIR__ . '/../../../../../../src';

    protected const string PACKAGE_CONFIG_FILE = __DIR__ . '/../../../../../../config/app/AI/agentApplicationWording.php';

    /** The package's prompt files that carry the context text of a space. */
    protected const array PACKAGE_PROMPT_FILES_WITH_THE_CONTEXT_LINE = [
        __DIR__ . '/../../../../../../config/app/AI/Prompts/AI/Agent/Agentic.md',
        __DIR__ . '/../../../../../../config/app/AI/Prompts/AI/Agent/General.md',
    ];

    /** @var array<string, mixed>|null the config values read so far, restored after every test */
    protected ?array $configTreeBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? $configTree->getValue() : null;
    }

    protected function tearDown(): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $this->configTreeBefore ?? []);
        parent::tearDown();
    }

    public function testThePackageFileDeclaresTheDefaultsOfTheClass(): void
    {
        $defaultWording = new AgentApplicationWording();

        $defaultsOfTheClass = [];
        foreach (AgentApplicationWording::DECLARED_PROPERTY_NAMES as $propertyName) {
            $defaultsOfTheClass[$propertyName] = $defaultWording->$propertyName;
        }
        self::assertSame($defaultsOfTheClass, require self::PACKAGE_CONFIG_FILE);
        self::assertSame('subscription', $defaultWording->billingEntityName);
        self::assertSame('workspace', $defaultWording->agentSpaceName);
        self::assertSame('', $defaultWording->ownerConsentSameTargetExamples);
        self::assertSame('Work context', $defaultWording->agentSpaceContextLabel);
        self::assertSame('work-context', $defaultWording->agentSpaceContextStateKind);
        self::assertSame('workspace', $defaultWording->agentSpaceContextSubjectName);
        self::assertSame('your work', $defaultWording->workContinuedAfterOwnerMessageName);
        self::assertSame('conversation', $defaultWording->seededPlanConversationName);
        self::assertSame('', $defaultWording->seededPlanBackgroundWorkExamples);
        self::assertSame('', $defaultWording->purposeBoundSkillLoadAdvice);
        self::assertSame('', $defaultWording->outwardActionKinds);
        self::assertSame('', $defaultWording->outwardActionConsentException);
        self::assertSame('holds the facts the application states', $defaultWording->skillPreloadFactsMeaning);
        self::assertSame('', $defaultWording->replyLanguageFurtherCoveredSentences);
        self::assertSame('the purpose\'s record', $defaultWording->criterionRecordName);
        self::assertSame('', $defaultWording->criterionStageToolExamples);
    }

    /** A state note names the context line by its label, so the prompt files have to write that label. */
    public function testThePromptFilesOfThePackageWriteTheDefaultLabelInFrontOfTheContextPlaceholder(): void
    {
        foreach (self::PACKAGE_PROMPT_FILES_WITH_THE_CONTEXT_LINE as $promptFile) {
            self::assertStringContainsString(
                "\n- " . AgentApplicationWording::DEFAULT_AGENT_SPACE_CONTEXT_LABEL . ': ' . AgentModelInput::AGENT_SPACE_CONTEXT_PLACEHOLDER . "\n",
                (string)file_get_contents($promptFile),
                basename($promptFile)
            );
        }
    }

    public function testAKeyTheApplicationLeavesOutOrDeclaresEmptyKeepsItsDefault(): void
    {
        $this->declareWordingOfTheApplication(['billingEntityName' => 'shop', 'agentSpaceName' => '', 'ownerConsentSameTargetExamples' => 7]);

        $agentApplicationWording = AgentApplicationWording::current();

        self::assertSame('shop', $agentApplicationWording->billingEntityName);
        self::assertSame(AgentApplicationWording::DEFAULT_AGENT_SPACE_NAME, $agentApplicationWording->agentSpaceName);
        self::assertSame('', $agentApplicationWording->ownerConsentSameTargetExamples);
    }

    public function testTheSentencesAboutPausedAutomationNameTheBillingEntityOfTheApplication(): void
    {
        $this->declareWordingOfTheApplication(['billingEntityName' => 'shop']);

        $endedEligibility = AgentAutomationEligibility::eligible(7);
        $endedEligibility->state = AgentAutomationEligibility::STATE_ENDED;
        $endedEligibility->reason = 'CONTRACT_ENDED';
        $suspendedEligibility = AgentAutomationEligibility::eligible(7);
        $suspendedEligibility->state = AgentAutomationEligibility::STATE_SUSPENDED;
        $suspendedEligibility->reason = 'PAYMENT_OVERDUE';

        self::assertStringStartsWith('This account or shop is no longer active (contract ended): ', $endedEligibility->describeForModel());
        self::assertStringStartsWith('This account or shop is currently locked (payment overdue): ', $suspendedEligibility->describeForModel());
        self::assertSame(
            'automated work is paused for your account or shop — I continue once it is active again.',
            AgentRunReport::buildStopReasonAutomationPaused()
        );
    }

    public function testTheReplyLanguageCueNamesTheSpaceWithTheWordOfTheApplication(): void
    {
        $this->declareWordingOfTheApplication(['agentSpaceName' => 'venue', 'agentSpaceContextSubjectName' => 'club']);
        $agentModelInput = new AgentModelInput();
        $agentModelInput->userLanguageCode = 'de';

        self::assertStringContainsString('club data in the venue\'s language, tool results', $agentModelInput->buildReplyLanguageTailCue());
    }

    public function testTheConsentCheckCarriesTheExamplesOfTheApplicationAndNoneWithoutThem(): void
    {
        $questionsWithoutExamples = ArgusAgentOwnerConsentDecision::buildQuestions();
        $criteriaWithoutExamples = $questionsWithoutExamples[ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED]['criteria'];
        self::assertStringContainsString('names this step with this target in any wording or language and says what follows from it as', $criteriaWithoutExamples['true']);
        self::assertStringContainsString('it names a different target; it offers the step only in general', $criteriaWithoutExamples['false']);

        $this->declareWordingOfTheApplication([
            'ownerConsentSameTargetExamples' => 'the same product, the same new price',
            'ownerConsentSameTargetWrittenDifferentlyExample' => 'the same price written with another decimal sign is the same target',
            'ownerConsentDifferentTargetExamples' => 'another product, another price',
        ]);
        $criteriaWithExamples = ArgusAgentOwnerConsentDecision::buildQuestions()[ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED]['criteria'];
        self::assertStringContainsString(
            'with this target (the same product, the same new price) in any wording or language — the same price written with another '
            . 'decimal sign is the same target — and says what follows from it as',
            $criteriaWithExamples['true']
        );
        self::assertStringContainsString('it names a different target (another product, another price); it offers', $criteriaWithExamples['false']);
    }

    public function testTheStateNotesNameTheContextLineItsKindAndItsSubjectWithTheWordsOfTheApplication(): void
    {
        self::assertSame(
            '[SIGNAL:work-context] The workspace record changed since the "Work context" line of your instructions was written — the values now:',
            AgentStateDeltaTexts::buildAgentSpaceContextLead()
        );
        self::assertSame('work-context', AgentStateDelta::getKindsInDeliveryOrder()[0]);

        $this->declareWordingOfTheApplication([
            'agentSpaceContextLabel' => 'Shop profile',
            'agentSpaceContextStateKind' => 'shop-profile',
            'agentSpaceContextSubjectName' => 'shop',
        ]);

        self::assertSame('shop-profile', AgentStateDelta::getKindOfAgentSpaceContext());
        self::assertSame(['shop-profile', AgentStateDelta::KIND_OWNER_ADDRESSING], AgentConversationState::getKindsAnEpochRecordKeepsUnknown());
        self::assertSame(
            '[SIGNAL:shop-profile] The shop record changed since the "Shop profile" line of your instructions was written — the values now:',
            AgentStateDeltaTexts::buildAgentSpaceContextLead()
        );
        self::assertSame(
            '[SIGNAL:shop-profile] The shop record is back to the values of the "Shop profile" line of your instructions — the earlier shop-profile notes no longer apply.',
            AgentStateDeltaTexts::buildAgentSpaceContextAsInInstructionsText()
        );
        self::assertSame(
            AgentStateDeltaTexts::buildAgentSpaceContextLead() . "\n- no shop profile any more\n" . AgentStateDeltaTexts::BACKGROUND_SUFFIX,
            AgentStateDeltaTexts::buildAgentSpaceContextText('Name: Rosa; ', null)
        );
        self::assertSame(
            '[SIGNAL:time-zone] The shop time zone is now Europe/London (was Europe/Berlin); the times in the brackets before this note are in Europe/Berlin, the later ones in Europe/London.',
            AgentStateDeltaTexts::buildTimeZoneText('Europe/Berlin', 'Europe/London', null, null)
        );
        $stateNotesRule = ArgusAIAgentConversation::buildStateNotesRule();
        self::assertStringStartsWith("\n- State notes — `[SIGNAL:shop-profile]`, `[SIGNAL:owner-addressing]`, `[SIGNAL:time-zone]`,", $stateNotesRule);
        self::assertStringContainsString('changed. The "Shop profile" line, the owner addressing,', $stateNotesRule);
        self::assertStringContainsString('Which note counts: the newest shop-profile, owner-addressing,', $stateNotesRule);
        self::assertStringContainsString('— its shop profile and owner addressing show today\'s values', AIConversationMessage::buildStateEpochUnreadableCompositionNote());
    }

    /** A note is stored with its kind and read back by it: the declared kind is the one the comparison uses. */
    public function testAChangedContextTextIsDeliveredAndAppliedUnderTheKindOfTheApplication(): void
    {
        $this->declareWordingOfTheApplication(['agentSpaceContextStateKind' => 'shop-profile']);
        $seenState = new AgentConversationState();
        $seenState->agentSpaceContext = 'Name: Rosa; ';
        $currentState = clone $seenState;
        $currentState->agentSpaceContext = 'Name: Rosa & Sohn; ';

        $agentStateDeltas = AgentStateChangeDetection::buildDeltasFor($seenState, $currentState);

        self::assertCount(1, $agentStateDeltas);
        self::assertSame('shop-profile', $agentStateDeltas[0]->kind);
        self::assertStringStartsWith('[SIGNAL:shop-profile] ', $agentStateDeltas[0]->text);
        self::assertSame('Name: Rosa & Sohn; ', $seenState->withDeltaApplied($agentStateDeltas[0])->agentSpaceContext);
    }

    public function testTheNoteOnAMessageTheOwnerSentMidTurnNamesTheWorkOfTheApplication(): void
    {
        $ownerMessage = new AIConversationMessage();
        $ownerMessage->indexInConversation = 4;
        $ownerMessage->message = 'Which colours do you stock?';
        $sourceMessages = new AIConversationMessages();
        $sourceMessages->add($ownerMessage);

        self::assertSame(
            'While you were working, the owner sent: (message 4) "Which colours do you stock?" — take it into account now: answer or '
            . 'acknowledge it in your next message, never re-ask what it already answers, and continue your work.',
            AIConversationMessage::buildMidTurnOwnerInboundNoteText($sourceMessages, [4])
        );

        $this->declareWordingOfTheApplication(['workContinuedAfterOwnerMessageName' => 'the order intake']);

        self::assertStringEndsWith(
            'never re-ask what it already answers, and continue the order intake.',
            AIConversationMessage::buildMidTurnOwnerInboundNoteText($sourceMessages, [4])
        );
    }

    public function testTheDelegationRefusalUnderASeededPlanNamesTheRunAndTheBackgroundWorkOfTheApplication(): void
    {
        self::assertSame(
            'Not delegated: in this conversation the platform already runs the background work and the acts are your own '
            . 'mid-dialogue work. Continue with the next plan item yourself; delegate only a self-contained package of the closing act.',
            DelegateTool::answerSeededPurposeRefusal()->text
        );

        $this->declareWordingOfTheApplication([
            'seededPlanConversationName' => 'order intake',
            'seededPlanBackgroundWorkExamples' => 'stock check, address validation',
        ]);

        self::assertStringStartsWith(
            'Not delegated: in this order intake the platform already runs the background work (stock check, address validation) '
            . 'and the acts are your own mid-dialogue work.',
            DelegateTool::answerSeededPurposeRefusal()->text
        );
    }

    public function testTheRefusalOfAPurposeBoundSkillCarriesTheAdviceOfTheApplicationAndNoneWithoutIt(): void
    {
        $aISkillLoadRefusals = new AISkillLoadRefusals();
        $aISkillLoadRefusals->purposeBoundSkillNames = ['Orders.Intake'];

        self::assertSame(
            ' NOT loaded (available only inside its own purposed conversation): Orders.Intake. Do not retry loading these here.',
            $aISkillLoadRefusals->renderCorrectiveSentences()
        );

        $this->declareWordingOfTheApplication(['purposeBoundSkillLoadAdvice' => 'An order intake starts with startOrderIntake.']);

        self::assertSame(
            ' NOT loaded (available only inside its own purposed conversation): Orders.Intake. Do not retry loading these here. '
            . 'An order intake starts with startOrderIntake.',
            $aISkillLoadRefusals->renderCorrectiveSentences()
        );
    }

    public function testTheHeadlineOfTheOutwardActionsNamesTheKindsAndTheExceptionOfTheApplicationAndNoneWithoutThem(): void
    {
        $aIConversationAuditRenderService = new AIConversationAuditRenderService();
        $conversation = new AIConversation();
        $conversation->messages = new AIConversationMessages();

        self::assertSame(
            "[OUTWARD ACTIONS — each needs the owner's explicit yes in a USER row BEFORE it]\n(none)",
            $aIConversationAuditRenderService->renderOutwardActions($conversation)
        );

        $this->declareWordingOfTheApplication([
            'outwardActionKinds' => 'orders placed, parcels sent',
            'outwardActionConsentException' => '(shop rule): a parcel label the owner printed counts as consented',
        ]);

        self::assertSame(
            "[OUTWARD ACTIONS — orders placed, parcels sent; each needs the owner's explicit yes in a USER row BEFORE it"
            . " — exception (shop rule): a parcel label the owner printed counts as consented]\n(none)",
            $aIConversationAuditRenderService->renderOutwardActions($conversation)
        );
    }

    public function testTheReplyLanguageCueCoversTheFurtherSentencesOfTheApplicationAndNoneWithoutThem(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->userLanguageCode = 'de';

        self::assertStringEndsWith('EVERY owner-visible sentence: answers, status updates.', $agentModelInput->buildReplyLanguageTailCue());

        $this->declareWordingOfTheApplication(['replyLanguageFurtherCoveredSentences' => 'every line of an order confirmation']);

        self::assertStringEndsWith(
            'EVERY owner-visible sentence: answers, status updates, and every line of an order confirmation.',
            $agentModelInput->buildReplyLanguageTailCue()
        );
    }

    public function testTheSupervisorBriefingTakesTheHeadlineOfItsStateSectionFromThePolicy(): void
    {
        self::assertSame(SupervisorPolicy::DEFAULT_AGENT_SPACE_STATE_HEADLINE, (new SupervisorPolicy())->getAgentSpaceStateHeadline());
        self::assertSame(SupervisorPolicy::DEFAULT_AGENT_SPACE_STATE_HEADLINE, (new SupervisorCheckpointBriefingInputs())->agentSpaceStateHeadline);
    }

    public function testTheSentencesAboutTheSpaceNameItWithTheWordOfTheApplication(): void
    {
        self::assertStringContainsString('why it does not apply to this workspace. An item', TaskPlan::buildTickRules());
        self::assertSame('No mandates. The agent holds no standing authorizations for this workspace.', (new AgentMandates())->toLlmMarkdown());

        $this->declareWordingOfTheApplication(['agentSpaceContextSubjectName' => 'club']);

        self::assertStringContainsString('why it does not apply to this club. An item', TaskPlan::buildTickRules());
        self::assertSame('No mandates. The agent holds no standing authorizations for this club.', (new AgentMandates())->toLlmMarkdown());
        self::assertStringContainsString('on behalf of the club; no person reads the chat live', OwnerPresence::WORKING_FOR_AN_EXTERNAL_AGENT->toSystemSegmentText());
    }

    public function testTheTurnStartQuestionsCarryTheExamplesOfTheApplicationAndNoneWithoutThem(): void
    {
        $workBeforeAnswerQuestion = ArgusAgentTurnStartDecision::buildTurnStartLineQuestions()[ArgusAgentTurnStartDecision::QUESTION_WORK_BEFORE_ANSWER];
        self::assertStringContainsString('read the workspace\'s own data, search, or change', $workBeforeAnswerQuestion['instructions']);
        self::assertStringContainsString('their own workspace data, results or figures', $workBeforeAnswerQuestion['criteria']['true']);
        $noDirectWidgetCriterion = ArgusAgentTurnStartDecision::buildDirectWidgetQuestions([])[ArgusAgentTurnStartDecision::QUESTION_DIRECT_WIDGET]['criteria'][AgentDirectWidgetDecision::ANSWER_NONE];
        self::assertStringContainsString('question about the workspace, a change to make', $noDirectWidgetCriterion);
        self::assertSame('workspace', ArgusAgentTurnStartDecision::agentSpaceFactsStateName());

        $this->declareWordingOfTheApplication([
            'agentSpaceContextSubjectName' => 'club',
            'agentSpaceFactsStateName' => 'club',
            'agentSpaceOwnDataExamples' => 'members, fixtures',
            'agentSpaceQuestionTopicExamples' => 'its members or fixtures',
        ]);

        $workBeforeAnswerQuestion = ArgusAgentTurnStartDecision::buildTurnStartLineQuestions()[ArgusAgentTurnStartDecision::QUESTION_WORK_BEFORE_ANSWER];
        self::assertStringContainsString('read the club\'s own data (members, fixtures), search, or change', $workBeforeAnswerQuestion['instructions']);
        $noDirectWidgetCriterion = ArgusAgentTurnStartDecision::buildDirectWidgetQuestions([])[ArgusAgentTurnStartDecision::QUESTION_DIRECT_WIDGET]['criteria'][AgentDirectWidgetDecision::ANSWER_NONE];
        self::assertStringContainsString('question about the club, its members or fixtures, a change to make', $noDirectWidgetCriterion);
        self::assertSame('club', ArgusAgentTurnStartDecision::agentSpaceFactsStateName());
    }

    public function testTheQuestionAboutARejectedIdentityOrClassSpeaksWithTheWordsOfTheApplication(): void
    {
        $identityQuestion = ArgusAgentTurnStartDecision::buildEffortQuestions(false)[ArgusAgentTurnStartDecision::QUESTION_REJECTS_IDENTITY_OR_CLASS];
        self::assertStringContainsString('declare that the identity of the workspace or the class it was put into that `', $identityQuestion['instructions']);
        self::assertSame(AgentApplicationWording::DEFAULT_IDENTITY_OR_CLASS_REJECTED_CRITERION, $identityQuestion['criteria']['true']);
        self::assertSame(AgentApplicationWording::DEFAULT_IDENTITY_OR_CLASS_NOT_REJECTED_CRITERION, $identityQuestion['criteria']['false']);

        $this->declareWordingOfTheApplication([
            'identityOrClassProposalSubject' => 'the club or its league',
            'identityOrClassRejectedCriterion' => 'the owner says it is another club',
            'identityOrClassNotRejectedCriterion' => 'the owner corrects one field',
        ]);

        $identityQuestion = ArgusAgentTurnStartDecision::buildEffortQuestions(false)[ArgusAgentTurnStartDecision::QUESTION_REJECTS_IDENTITY_OR_CLASS];
        self::assertStringContainsString('declare that the club or its league that `', $identityQuestion['instructions']);
        self::assertSame('the owner says it is another club', $identityQuestion['criteria']['true']);
        self::assertSame('the owner corrects one field', $identityQuestion['criteria']['false']);
    }

    public function testAStoredEffortDecisionWithTheFormerKeyIsStillRead(): void
    {
        $storedBeforeTheRename = new AgentReasoningEffortDecision();
        $storedBeforeTheRename->mapFromRepository([AgentReasoningEffortDecision::FORMER_STORED_KEY_OF_REJECTS_IDENTITY_OR_CLASS_PROBABILITY => 0.9]);
        self::assertSame(0.9, $storedBeforeTheRename->rejectsIdentityOrClassProbability);

        $storedNow = new AgentReasoningEffortDecision();
        $storedNow->mapFromRepository(['rejectsIdentityOrClassProbability' => 0.4]);
        self::assertSame(0.4, $storedNow->rejectsIdentityOrClassProbability);
    }

    public function testNoWidgetOfTheModuleProposesAnIdentityOrAClass(): void
    {
        self::assertFalse(ConversationWidgetClasses::isOneOfWidgetTypesProposingIdentityOrClass(ConversationWidgetClasses::getWidgetTypes()));
        self::assertFalse(ConversationWidgetClasses::isOneOfWidgetTypesProposingIdentityOrClass(['NoSuchWidgetType']));
    }

    /** The words one application uses for its billing entity and its space stand in no sentence of the module. */
    public function testNoSentenceOfTheModuleHardcodesTheWordsOfOneApplication(): void
    {
        $linesWithHardcodedWords = [];
        $sourceFolder = (string)realpath(self::SOURCE_FOLDER);
        $phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceFolder, FilesystemIterator::SKIP_DOTS));
        foreach ($phpFiles as $phpFile) {
            if ($phpFile->getExtension() !== 'php') {
                continue;
            }
            foreach (file($phpFile->getPathname()) ?: [] as $lineNumber => $line) {
                if (preg_match('/project or account|account or project|location\\?\'s language|PROJECT STATE|BUSINESS_CONTEXT|SIGNAL:business-context|Business context|business record|business time zone|continue the setup|in this setup|guided setup|build record|website replacements|whether a website exists|after a handover|this business|THIS business|business\\\\?\'s|business data|business facts|business assistant|business owner|BUSINESS_LOCALE|BusinessMatchConfirm|identity_or_business_class/', $line) === 1) {
                    $linesWithHardcodedWords[] = substr($phpFile->getPathname(), strlen($sourceFolder) + 1) . ':' . ($lineNumber + 1);
                }
            }
        }

        self::assertSame([], $linesWithHardcodedWords);
    }

    /**
     * Writes the application's wording where {@see Config::get()} reads it first: the values it has already read.
     *
     * @param array<string, mixed> $wordingOfTheApplication
     */
    protected function declareWordingOfTheApplication(array $wordingOfTheApplication): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? $configTree->getValue() : [];
        $configValues['AI']['agentApplicationWording'] = $wordingOfTheApplication;
        $configTree->setValue(null, $configValues);
    }
}
