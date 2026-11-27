<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Entities\Conversations\Widgets\MailDraftPreviewConversationWidget;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixture;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureCase;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureCases;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureRunSettings;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureScope;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureScore;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Synthetic decision calls for fast iteration on the decision model's questions: a JSON fixture holds cases, every case
 * is sent to the decision model as the loop would send it, and the answer is compared with the expectation. One run =
 * one scorecard.
 *
 * Sub-skill pass fixture (no `site`): the owner message, the assistant text before it, the recent turns, the plan
 * render, the loaded root and its resident sub-skills, the expected pick. The catalog entries, the root's tools and its
 * description come from the live skills:
 *   {"agentSpaceId": 7, "cases": [{"id": "...", "rootSkill": "...", "loadedSubSkills": ["..."], "ownerMessage": "...",
 *     "assistantTextBefore": "...", "openWorkItem": "...", "recentTurns": ["owner: ...", "assistant: ..."],
 *     "plan": "[✓] 1 ...\n[ ] 2 ... ← next", "agentSpaceFacts": "...", "actsAhead": ["..."],
 *     "expected": "<sub-skill token>" | "none"}]}
 *
 * Reply-language fixture (`"site": "replyLanguage"`): the owner's words, the sticky anchor (`conversationLanguage`),
 * the assistant's last text and the recent turns; scored on the model's written and requested language answers and on
 * the language the reply chain resolves with that anchor:
 *   {"site": "replyLanguage", "agentSpaceId": 7, "supportedLanguageCodes": ["en", ...], "cases": [{"id": "...",
 *     "conversationLanguage": "en" | null, "recentTurns": [...], "assistantTextBefore": "...", "ownerWords": "...",
 *     "expectedWritten": "en" | "undecidable", "expectedRequested": "none" | "fr", "expectedReply": "en"}]}
 * `--without-context` sends the context-free written-language question (the comparison arm of a measurement).
 *
 * Owner-consent fixture (`"site": "ownerConsent"`): the owner's request, the assistant's text that showed the step
 * (with its question), the owner's next message; the step's target and consequence; `shownContent` "true" when the
 * assistant's text carries the step's exact content (the evidence of a tool such as a mail send). The dialogue is built
 * from these rows by the live code ({@see OwnerConsentsService::buildOwnerConsentDialogue()},
 * {@see OwnerConsentsService::addShownContentLine()}) and decided by the live model; scored on consent vs expectation:
 *   {"site": "ownerConsent", "agentSpaceId": 7, "cases": [{"id": "...", "ownerRequest": "...", "assistantText": "...",
 *     "ownerMessage": "...", "toolName": "sendMail", "actionKind": "...", "targetLine": "...", "consequenceLine": "...",
 *     "shownContent": "true", "expected": "consent" | "refuse"}]}
 * With `"shownAs": "mailPreview"` (and `mailTo`, comma-separated, `mailSubject`, `mailBody`) the content is shown as the
 * mail tool's DRAFT preview row instead, and `assistantText` is the agent's one line under it.
 *
 * The run acts as the account that owns the space the fixture names ({@see resolveBillingScope()}); every call is
 * booked under its own evaluation envelope for that account and billed to the space's billing entity. An application
 * whose fixtures name the scope by a key of its own overrides {@see getFixtureScopeKey()} and
 * {@see resolveBillingScope()}, its case key of the space's facts {@see getAgentSpaceFactsKey()}; its default
 * languages through {@see getDefaultSupportedLanguageCodes()} and
 * {@see getDefaultReplyLanguageCode()}.
 */
#[AsCommand(
    name: 'app:agent:decision-fixtures',
    aliases: ['app:ai-agent:decision-fixtures'],
    description: 'Send synthetic sub-skill-preload cases from a JSON fixture to the decision model and score the picks against the expectations.'
)]
class AgentDecisionFixturesCommand extends AgentConsoleCommand
{
    /** @var string The fixture key that names the agent space by its id */
    public const string FIXTURE_AGENT_SPACE_ID_KEY = 'agentSpaceId';

    /** @var string The case key that holds the facts of the agent space the decision reads */
    public const string CASE_AGENT_SPACE_FACTS_KEY = 'agentSpaceFacts';

    /** @var string The batch key of a fixture decision */
    public const string DECISION_KEY_IN_BATCH = 'fixture';

    /** @var string[] The languages a reply may take when the fixture names none */
    public const array DEFAULT_SUPPORTED_LANGUAGE_CODES = ['en'];

    /** @var string The language the reply chain falls back to when a case has no anchor */
    public const string DEFAULT_REPLY_LANGUAGE_CODE = 'en';

    /** @var string[] The table headers of a sub-skill pass run */
    public const array SUB_SKILL_TABLE_HEADERS = ['case', 'picked (conf)', 'expected', '', 'top-3 · latency'];

    /** @var string[] The table headers of a reply-language run */
    public const array REPLY_LANGUAGE_TABLE_HEADERS = ['case', 'written (conf)', 'requested (conf)', 'reply', 'anchor', 'latency'];

    protected function configure(): void
    {
        $this
            ->addArgument('fixture', InputArgument::REQUIRED, 'Path of the JSON fixture (absolute, or relative to the app dir)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run only the case with this id')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Send every case this many times (variance)', '1')
            ->addOption('show-state', null, InputOption::VALUE_NONE, 'Print the state object sent for each case');
        $this->configureIgnoreBudgetOption();
        $this->addOption('without-context', null, InputOption::VALUE_NONE, 'replyLanguage fixtures: send the context-free written-language question (no anchor, no recent turns)');
    }

    /**
     * Reads the fixture, resolves whom the calls are made for, and runs its cases as the sub-skill pass or the
     * reply-language site.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `FAILURE` when the fixture is missing, holds no case to run or names no resolvable scope
     * @throws JsonException when the fixture is no valid JSON
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(900);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $fixturePath = (string)$input->getArgument('fixture');
        if (!is_file($fixturePath)) {
            $symfonyStyle->error("Fixture not found: $fixturePath");
            return Command::FAILURE;
        }
        $agentDecisionFixture = AgentDecisionFixture::fromJson((string)file_get_contents($fixturePath));
        $onlyCaseId = $input->getOption('only');
        $agentDecisionFixtureCases = $agentDecisionFixture->getCases()->buildWithId($onlyCaseId !== null ? (string)$onlyCaseId : null);
        if ($agentDecisionFixtureCases->count() === 0) {
            $symfonyStyle->error('No cases to run.');
            return Command::FAILURE;
        }
        $agentDecisionFixtureScope = $this->resolveBillingScopeWithRightsLifted($agentDecisionFixture);
        if ($agentDecisionFixtureScope === null) {
            $symfonyStyle->error("The fixture's {$this->getFixtureScopeKey()} or its owning account could not be resolved.");
            return Command::FAILURE;
        }
        $this->actAsAccount($agentDecisionFixtureScope->ownerAccount);
        $agentDecisionFixtureRunSettings = AgentDecisionFixtureRunSettings::forRun(
            (int)$input->getOption('repeat'),
            (bool)$input->getOption('show-state'),
            (bool)$input->getOption('without-context'),
            AgentDecisionModelSettings::current()->timeoutSeconds
        );
        if ($agentDecisionFixture->getSite() === AgentDecisionModelSettings::SITE_OWNER_CONSENT) {
            return $this->runOwnerConsentCases($agentDecisionFixtureCases, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $symfonyStyle);
        }
        if ($agentDecisionFixture->getSite() === AgentDecisionModelSettings::SITE_REPLY_LANGUAGE) {
            return $this->runReplyLanguageCases($agentDecisionFixtureCases, $agentDecisionFixture, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $symfonyStyle);
        }
        return $this->runSubSkillPreloadCases($agentDecisionFixtureCases, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $symfonyStyle);
    }

    /** @var string[] The table of an owner-consent run */
    public const array OWNER_CONSENT_TABLE_HEADERS = ['case', 'expected', 'verdict', 'step explained', 'owner agreed', 'ms'];

    protected function runOwnerConsentCases(
        AgentDecisionFixtureCases $agentDecisionFixtureCases,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        SymfonyStyle $symfonyStyle
    ): int {
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        foreach ($agentDecisionFixtureCases->getElements() as $agentDecisionFixtureCase) {
            for ($callNumber = 0; $callNumber < $agentDecisionFixtureRunSettings->repeat; $callNumber++) {
                $this->runOwnerConsentCase($agentDecisionFixtureCase, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $agentDecisionFixtureScore);
            }
        }
        $agentDecisionFixtureScore->renderTable($symfonyStyle, static::OWNER_CONSENT_TABLE_HEADERS);
        $symfonyStyle->writeln($agentDecisionFixtureScore->buildPickScoreLine());
        return Command::SUCCESS;
    }

    /** One owner-consent case: the rows, the live dialogue builder, the live decision, the verdict against the expectation */
    protected function runOwnerConsentCase(
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        AgentDecisionFixtureScore $agentDecisionFixtureScore
    ): void {
        $caseId = $agentDecisionFixtureCase->getId();
        $messages = AIConversationMessages::newInstance();
        $ownerRequestRow = $this->buildOwnerConsentRow(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, $agentDecisionFixtureCase->getText('ownerRequest'));
        $messages->add($ownerRequestRow);
        $nextMessageIndex = 1;
        $shownAsMailPreview = $agentDecisionFixtureCase->getText('shownAs') === 'mailPreview';
        if ($shownAsMailPreview) {
            // the tool-written preview row (the live sendMail's DRAFT preview), its content hash = the case's target key
            $mailDraft = AgentConnectionMailDraft::newInstance();
            $mailDraft->to = array_values(array_filter(array_map('trim', explode(',', $agentDecisionFixtureCase->getText('mailTo')))));
            $mailDraft->subject = $agentDecisionFixtureCase->getText('mailSubject');
            $mailDraft->bodyText = $agentDecisionFixtureCase->getText('mailBody');
            $mailDraftPreviewWidget = MailDraftPreviewConversationWidget::forMailDraft($mailDraft, 0, 'owner@example.org', $caseId, MailDraftPreviewConversationWidget::STATUS_DRAFT);
            $previewRow = $this->buildOwnerConsentRow($nextMessageIndex++, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, $mailDraftPreviewWidget->buildWidgetRowEmittedBy(ConversationWidgetOrigin::tool('sendMail')));
            $previewRow->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
            $messages->add($previewRow);
        }
        $shownContentMessageIndex = $nextMessageIndex - ($shownAsMailPreview ? 1 : 0);
        $assistantRow = $this->buildOwnerConsentRow($nextMessageIndex++, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $agentDecisionFixtureCase->getText('assistantText'));
        $ownerMessageRow = $this->buildOwnerConsentRow($nextMessageIndex, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, $agentDecisionFixtureCase->getText('ownerMessage'));
        $messages->add($assistantRow, $ownerMessageRow);
        $ownerConsentRequest = OwnerConsentRequest::forAction(
            $agentDecisionFixtureCase->getText('toolName'),
            $agentDecisionFixtureCase->getText('actionKind'),
            $agentDecisionFixtureCase->getText('targetLine'),
            $agentDecisionFixtureCase->getText('consequenceLine'),
            $caseId
        );
        if ($agentDecisionFixtureCase->getText('shownContent') === 'true') {
            $ownerConsentRequest->shownContentMessageIndex = $shownContentMessageIndex;
        }
        $ownerConsentsService = $this->getOwnerConsentsService();
        $dialogue = $ownerConsentsService->buildOwnerConsentDialogue($messages, -1, null);
        if ($ownerConsentsService->addShownContentLine($dialogue, $messages, $ownerConsentRequest)) {
            // as the live check: the checked content answers whether the step was explained
            $ownerConsentRequest->minimumStepExplainedProbability = 0.0;
            $ownerConsentRequest->minimumProbability = $ownerConsentRequest->minimumOwnerAgreedProbability;
        }
        $startedAtNanoseconds = hrtime(true);
        $usageEnvelopeContext = $this->openUsageEnvelope($agentDecisionFixtureScope->getOwnerAccountId(), AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
        try {
            $ownerConsentDecisionResult = $ownerConsentsService->decideDialogue(
                $ownerConsentRequest,
                $dialogue,
                ArgusAgentOwnerConsentDecision::CHANNEL_CHAT,
                null,
                $agentDecisionFixtureScope->ownerAccount,
                OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL,
                $agentDecisionFixtureRunSettings->timeoutSeconds
            );
        } catch (Throwable $callError) {
            $agentDecisionFixtureScore->addTableRow($caseId, 'ERROR ' . mb_substr($callError->getMessage(), 0, 80), '', '', '', '');
            return;
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
        $elapsedMs = $this->findElapsedMilliseconds($startedAtNanoseconds);
        $agentDecisionFixtureScore->recordLatencyMs($elapsedMs);
        $ownerConsentVerdict = $ownerConsentDecisionResult->verdict;
        $verdictText = $ownerConsentVerdict->consented ? 'consent' : 'refuse';
        $expectedText = $agentDecisionFixtureCase->getText('expected');
        $agentDecisionFixtureScore->recordScoredPick($verdictText === $expectedText);
        $agentDecisionFixtureScore->addTableRow(
            $caseId,
            $expectedText,
            $verdictText . ($verdictText === $expectedText ? '' : ' ✗') . ($ownerConsentVerdict->failure !== null ? ' (' . $ownerConsentVerdict->failure . ')' : ''),
            $ownerConsentVerdict->stepExplainedProbability !== null ? number_format((float)$ownerConsentVerdict->stepExplainedProbability, 2) : '-',
            $ownerConsentVerdict->ownerAgreedProbability !== null ? number_format((float)$ownerConsentVerdict->ownerAgreedProbability, 2) : '-',
            (string)$elapsedMs
        );
    }

    protected function buildOwnerConsentRow(int $messageIndex, string $role, string $authorType, string $text): AIConversationMessage
    {
        $row = AIConversationMessage::newInstance();
        $row->indexInConversation = $messageIndex;
        $row->role = $role;
        $row->authorType = $authorType;
        $row->messageType = AIConversationMessage::MESSAGE_TEXT;
        $row->message = $text;
        return $row;
    }

    protected function getOwnerConsentsService(): OwnerConsentsService
    {
        /** @var OwnerConsentsService $ownerConsentsService */
        $ownerConsentsService = DDDService::instance()->getService(OwnerConsentsService::class);
        return $ownerConsentsService;
    }

    // ------------------------------------------------------------------ the scope of the calls

    /**
     * The fixture key that names the scope of the calls (it appears in the error when the scope cannot be resolved).
     * The default: `agentSpaceId`.
     *
     * @return string
     */
    protected function getFixtureScopeKey(): string
    {
        return self::FIXTURE_AGENT_SPACE_ID_KEY;
    }

    /**
     * {@see resolveBillingScope()} with the entity rights lifted: whoever runs the fixture, the owner of what it names
     * must be found.
     *
     * @param AgentDecisionFixture $agentDecisionFixture
     * @return AgentDecisionFixtureScope|null
     * @throws Throwable
     */
    protected function resolveBillingScopeWithRightsLifted(AgentDecisionFixture $agentDecisionFixture): ?AgentDecisionFixtureScope
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->resolveBillingScope($agentDecisionFixture);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Whom the calls of the fixture are made for; asked with the entity rights lifted. The default reads the agent
     * space id under {@see getFixtureScopeKey()}: the space's owner account and the space's billing entity.
     *
     * @param AgentDecisionFixture $agentDecisionFixture
     * @return AgentDecisionFixtureScope|null null = the fixture names no stored space, or a space without an owner
     * @throws Throwable
     */
    protected function resolveBillingScope(AgentDecisionFixture $agentDecisionFixture): ?AgentDecisionFixtureScope
    {
        $agentSpaceId = $agentDecisionFixture->getInteger($this->getFixtureScopeKey()) ?? 0;
        if ($agentSpaceId <= 0) {
            return null;
        }
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpace = $agentSpacesService->find($agentSpaceId);
        if (!$agentSpace instanceof AgentSpace) {
            return null;
        }
        $ownerAccount = $this->findOwnerAccountOfAgentSpace($agentSpace);
        if (!$ownerAccount instanceof Account) {
            return null;
        }
        return new AgentDecisionFixtureScope($ownerAccount, $agentSpace->findBillingEntity());
    }

    /**
     * The case key that holds the facts of the agent space a sub-skill pass case gives the decision. The default:
     * `agentSpaceFacts`.
     *
     * @return string
     */
    protected function getAgentSpaceFactsKey(): string
    {
        return self::CASE_AGENT_SPACE_FACTS_KEY;
    }

    // ------------------------------------------------------------------ the decision call

    /**
     * One decision call inside an evaluation envelope opened for the scope's owner and closed in a `finally`.
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param float $timeoutSeconds
     * @return void
     * @throws Throwable
     */
    protected function loadDecisionInsideEvaluationEnvelope(ArgusAgentTurnStartDecision $argusDecision, AgentDecisionFixtureScope $agentDecisionFixtureScope, float $timeoutSeconds): void
    {
        $usageEnvelopeContext = $this->openUsageEnvelope($agentDecisionFixtureScope->getOwnerAccountId(), AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
        try {
            $this->loadDecision($argusDecision, $timeoutSeconds);
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
    }

    /**
     * The model call of one case: the decision as the only child of a decision batch.
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param float $timeoutSeconds
     * @return void
     * @throws Throwable
     */
    protected function loadDecision(ArgusAgentTurnStartDecision $argusDecision, float $timeoutSeconds): void
    {
        ArgusAgentDecisions::forChild($argusDecision, self::DECISION_KEY_IN_BATCH)->loadDecisions($timeoutSeconds);
    }

    /**
     * Prints the state sent for a case (once per case, before its first call).
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param string $caseId
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printStateOfCase(ArgusAgentTurnStartDecision $argusDecision, string $caseId, SymfonyStyle $symfonyStyle): void
    {
        $symfonyStyle->section('State of ' . $caseId);
        $symfonyStyle->writeln((string)json_encode($argusDecision->buildState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The milliseconds since `$startedAtNanoseconds` (an `hrtime(true)` value). */
    protected function findElapsedMilliseconds(int|float $startedAtNanoseconds): int
    {
        return (int)round((hrtime(true) - $startedAtNanoseconds) / 1e6);
    }

    // ------------------------------------------------------------------ the sub-skill pass

    /**
     * The sub-skill pass cases: every case sent `repeat` times, one table row per call, the pick score under it.
     *
     * @param AgentDecisionFixtureCases $agentDecisionFixtureCases
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @param SymfonyStyle $symfonyStyle
     * @return int `SUCCESS`
     */
    protected function runSubSkillPreloadCases(
        AgentDecisionFixtureCases $agentDecisionFixtureCases,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        SymfonyStyle $symfonyStyle
    ): int {
        $aISkillsService = $this->getSkillsService();
        $skillCatalog = $aISkillsService->getCatalog(); // nothing is gated here: a sub-skill is named from the full catalog
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        foreach ($agentDecisionFixtureCases->getElements() as $agentDecisionFixtureCase) {
            $this->runSubSkillPreloadCase($agentDecisionFixtureCase, $skillCatalog, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $agentDecisionFixtureScore, $symfonyStyle);
        }
        $agentDecisionFixtureScore->renderTable($symfonyStyle, static::SUB_SKILL_TABLE_HEADERS);
        $symfonyStyle->writeln($agentDecisionFixtureScore->buildPickScoreLine());
        return Command::SUCCESS;
    }

    /**
     * One sub-skill pass case: the root skill and its sub-skills from the live skills, then `repeat` calls, each scored
     * against the case's `expected` pick.
     *
     * @param AgentDecisionFixtureCase $agentDecisionFixtureCase
     * @param AISkills $skillCatalog
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @param AgentDecisionFixtureScore $agentDecisionFixtureScore
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function runSubSkillPreloadCase(
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        AISkills $skillCatalog,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        AgentDecisionFixtureScore $agentDecisionFixtureScore,
        SymfonyStyle $symfonyStyle
    ): void {
        $caseId = $agentDecisionFixtureCase->getId();
        $aISkillsService = $this->getSkillsService();
        $rootSkill = $aISkillsService->getAISkillByName($agentDecisionFixtureCase->getText('rootSkill'));
        if (!$rootSkill instanceof AISkill) {
            $agentDecisionFixtureScore->addTableRow($caseId, 'root skill not found', '', '', '');
            return;
        }
        $loadedSubSkillNames = $agentDecisionFixtureCase->getTexts('loadedSubSkills');
        $loadedSubSkillCriteriaByName = [];
        $catalogEntriesBySubSkillName = [];
        foreach ($rootSkill->subSkillNames as $subSkillName) {
            $subSkill = $aISkillsService->getAISkillByName($subSkillName);
            if ($subSkill === null) {
                continue;
            }
            if (in_array($subSkillName, $loadedSubSkillNames, true)) {
                $loadedSubSkillCriteriaByName[$subSkillName] = $subSkill->shortDescription !== '' ? $subSkill->shortDescription : $subSkill->getDecisionCriteria();
                continue;
            }
            $catalogEntriesBySubSkillName[$subSkillName] = $subSkill->getDecisionCatalogEntry($skillCatalog);
        }
        $expectedPick = strtolower($agentDecisionFixtureCase->getText('expected'));
        for ($callNumber = 0; $callNumber < $agentDecisionFixtureRunSettings->repeat; $callNumber++) {
            $argusDecision = $this->buildSubSkillPreloadDecision($agentDecisionFixtureCase, $rootSkill, $loadedSubSkillCriteriaByName, $catalogEntriesBySubSkillName, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings);
            if ($agentDecisionFixtureRunSettings->showState && $callNumber === 0) {
                $this->printStateOfCase($argusDecision, $caseId, $symfonyStyle);
            }
            $startedAtNanoseconds = hrtime(true);
            try {
                $this->loadDecisionInsideEvaluationEnvelope($argusDecision, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings->timeoutSeconds);
            } catch (Throwable $callError) {
                $agentDecisionFixtureScore->addTableRow($caseId, 'ERROR ' . mb_substr($callError->getMessage(), 0, 80), $expectedPick, '', '');
                continue;
            }
            $this->scoreSubSkillPick($argusDecision, $caseId, $expectedPick, $this->findElapsedMilliseconds($startedAtNanoseconds), $agentDecisionFixtureScore);
        }
    }

    /**
     * The decision of one sub-skill pass call, as the loop builds it for a loaded root.
     *
     * @param AgentDecisionFixtureCase $agentDecisionFixtureCase
     * @param AISkill $rootSkill
     * @param array<string, string> $loadedSubSkillCriteriaByName the resident sub-skills and their criteria line
     * @param array<string, string> $catalogEntriesBySubSkillName the sub-skills offered and their catalog entry
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @return ArgusAgentTurnStartDecision
     */
    protected function buildSubSkillPreloadDecision(
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        AISkill $rootSkill,
        array $loadedSubSkillCriteriaByName,
        array $catalogEntriesBySubSkillName,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
    ): ArgusAgentTurnStartDecision {
        $argusDecision = ArgusAgentTurnStartDecision::newInstance();
        $argusDecision->preloadLoadedRootSkillName = $rootSkill->name;
        $argusDecision->preloadRootSkillDescription = (string)preg_replace('/\s+/', ' ', trim((string)($rootSkill->description ?? '')));
        $argusDecision->preloadLoadedRootSkillTools = $rootSkill->getToolSummaries(null, AISkill::MAX_DECISION_OWN_TOOL_SUMMARY_CHARS);
        $argusDecision->preloadLoadedSubSkills = $loadedSubSkillCriteriaByName;
        $argusDecision->preloadCatalog = $catalogEntriesBySubSkillName;
        $argusDecision->preloadOwnerMessage = $agentDecisionFixtureCase->getText('ownerMessage');
        $argusDecision->preloadAssistantTextBefore = $agentDecisionFixtureCase->getText('assistantTextBefore');
        $argusDecision->preloadOpenWorkItem = $agentDecisionFixtureCase->getText('openWorkItem');
        $argusDecision->preloadRecentTurns = $agentDecisionFixtureCase->getTexts('recentTurns');
        $argusDecision->preloadAgentSpaceFacts = $agentDecisionFixtureCase->getText($this->getAgentSpaceFactsKey());
        $argusDecision->preloadPlan = $agentDecisionFixtureCase->getText('plan');
        $argusDecision->preloadActsAhead = $agentDecisionFixtureCase->getTexts('actsAhead');
        $argusDecision->billingEntity = $agentDecisionFixtureScope->billingEntity;
        $argusDecision->budgetAccount = $agentDecisionFixtureScope->ownerAccount;
        $argusDecision->setTimeoutSeconds($agentDecisionFixtureRunSettings->timeoutSeconds);
        return $argusDecision;
    }

    /**
     * Scores an answered sub-skill pass call against `$expectedPick` (no expectation = not scored) and adds its row:
     * the pick with its confidence, the expectation, HIT / MISS, the top three probabilities and the latency.
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param string $caseId
     * @param string $expectedPick
     * @param int $elapsedMs
     * @param AgentDecisionFixtureScore $agentDecisionFixtureScore
     * @return void
     */
    protected function scoreSubSkillPick(ArgusAgentTurnStartDecision $argusDecision, string $caseId, string $expectedPick, int $elapsedMs, AgentDecisionFixtureScore $agentDecisionFixtureScore): void
    {
        $pickedToken = (string)($argusDecision->preloadPickedToken ?? '(no answer)');
        $probabilitiesByToken = $argusDecision->preloadProbabilities;
        arsort($probabilitiesByToken);
        $topProbabilityTexts = [];
        foreach (array_slice($probabilitiesByToken, 0, 3, true) as $token => $probability) {
            $topProbabilityTexts[] = $token . ' ' . number_format((float)$probability, 2);
        }
        $hit = $expectedPick !== '' && $pickedToken === $expectedPick;
        if ($expectedPick !== '') {
            $agentDecisionFixtureScore->recordScoredPick($hit);
        }
        $agentDecisionFixtureScore->addTableRow(
            $caseId,
            $pickedToken . ' (' . number_format($argusDecision->preloadPickConfidence, 2) . ')',
            $expectedPick,
            $expectedPick === '' ? '' : ($hit ? 'HIT' : 'MISS'),
            implode(' · ', $topProbabilityTexts) . " · {$elapsedMs} ms"
        );
    }

    // ------------------------------------------------------------------ the reply-language site

    /**
     * The languages a reply may take when the fixture names none. The default: {@see DEFAULT_SUPPORTED_LANGUAGE_CODES}.
     *
     * @return string[]
     */
    protected function getDefaultSupportedLanguageCodes(): array
    {
        return static::DEFAULT_SUPPORTED_LANGUAGE_CODES;
    }

    /**
     * The language the reply chain falls back to when a case has no anchor. The default:
     * {@see DEFAULT_REPLY_LANGUAGE_CODE}.
     *
     * @return string
     */
    protected function getDefaultReplyLanguageCode(): string
    {
        return static::DEFAULT_REPLY_LANGUAGE_CODE;
    }

    /**
     * The name of the measurement arm in the score line of a reply-language run.
     *
     * @param bool $withoutContext
     * @return string
     */
    protected function getReplyLanguageArmLabel(bool $withoutContext): string
    {
        return $withoutContext ? 'without-context' : 'with-context';
    }

    /**
     * The reply-language cases: one request per call carrying only the language site, scored three ways — the written
     * answer, the requested answer and the reply the reply chain resolves with the case's anchor.
     *
     * @param AgentDecisionFixtureCases $agentDecisionFixtureCases
     * @param AgentDecisionFixture $agentDecisionFixture
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @param SymfonyStyle $symfonyStyle
     * @return int `SUCCESS`
     */
    protected function runReplyLanguageCases(
        AgentDecisionFixtureCases $agentDecisionFixtureCases,
        AgentDecisionFixture $agentDecisionFixture,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        SymfonyStyle $symfonyStyle
    ): int {
        $supportedLanguageCodes = $agentDecisionFixture->getSupportedLanguageCodes($this->getDefaultSupportedLanguageCodes());
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        foreach ($agentDecisionFixtureCases->getElements() as $agentDecisionFixtureCase) {
            $this->runReplyLanguageCase($agentDecisionFixtureCase, $supportedLanguageCodes, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings, $agentDecisionFixtureScore, $symfonyStyle);
        }
        $agentDecisionFixtureScore->renderTable($symfonyStyle, static::REPLY_LANGUAGE_TABLE_HEADERS);
        $symfonyStyle->writeln($agentDecisionFixtureScore->buildReplyLanguageScoreLine($this->getReplyLanguageArmLabel($agentDecisionFixtureRunSettings->withoutContext)));
        return Command::SUCCESS;
    }

    /**
     * One reply-language case, sent `repeat` times.
     *
     * @param AgentDecisionFixtureCase $agentDecisionFixtureCase
     * @param string[] $supportedLanguageCodes
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @param AgentDecisionFixtureScore $agentDecisionFixtureScore
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function runReplyLanguageCase(
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        array $supportedLanguageCodes,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings,
        AgentDecisionFixtureScore $agentDecisionFixtureScore,
        SymfonyStyle $symfonyStyle
    ): void {
        $caseId = $agentDecisionFixtureCase->getId();
        for ($callNumber = 0; $callNumber < $agentDecisionFixtureRunSettings->repeat; $callNumber++) {
            $argusDecision = $this->buildReplyLanguageDecision($agentDecisionFixtureCase, $supportedLanguageCodes, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings);
            if ($agentDecisionFixtureRunSettings->showState && $callNumber === 0) {
                $this->printStateOfCase($argusDecision, $caseId, $symfonyStyle);
            }
            $startedAtNanoseconds = hrtime(true);
            try {
                $this->loadDecisionInsideEvaluationEnvelope($argusDecision, $agentDecisionFixtureScope, $agentDecisionFixtureRunSettings->timeoutSeconds);
            } catch (Throwable $callError) {
                $agentDecisionFixtureScore->addTableRow($caseId, 'ERROR ' . mb_substr($callError->getMessage(), 0, 80), '', '', '', '');
                continue;
            }
            $agentDecisionFixtureScore->recordLatencyMs($this->findElapsedMilliseconds($startedAtNanoseconds));
            $this->scoreReplyLanguageAnswer($argusDecision, $agentDecisionFixtureCase, $supportedLanguageCodes, $agentDecisionFixtureScore);
        }
    }

    /**
     * The decision of one reply-language call: the owner's words, and — unless the run goes without context — the
     * anchor, the assistant's last text and the recent turns. A first-turn case without any context still gets the
     * context-aware question (its anchor line says "none yet").
     *
     * @param AgentDecisionFixtureCase $agentDecisionFixtureCase
     * @param string[] $supportedLanguageCodes
     * @param AgentDecisionFixtureScope $agentDecisionFixtureScope
     * @param AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
     * @return ArgusAgentTurnStartDecision
     */
    protected function buildReplyLanguageDecision(
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        array $supportedLanguageCodes,
        AgentDecisionFixtureScope $agentDecisionFixtureScope,
        AgentDecisionFixtureRunSettings $agentDecisionFixtureRunSettings
    ): ArgusAgentTurnStartDecision {
        $argusDecision = ArgusAgentTurnStartDecision::newInstance();
        $argusDecision->ownerWordsThisTurn = $agentDecisionFixtureCase->getText('ownerWords');
        $argusDecision->supportedLanguageCodes = $supportedLanguageCodes;
        if (!$agentDecisionFixtureRunSettings->withoutContext) {
            $argusDecision->languageConversationLanguageCode = $agentDecisionFixtureCase->getNullableText('conversationLanguage');
            $argusDecision->languageAssistantTextBefore = $agentDecisionFixtureCase->getText('assistantTextBefore');
            $argusDecision->languageRecentTurns = $agentDecisionFixtureCase->getTexts('recentTurns');
            if (!$argusDecision->carriesLanguageContext()) {
                $argusDecision->languageAssistantTextBefore = '';
                $argusDecision->languageRecentTurns = [];
                $argusDecision->languageConversationLanguageCode = null;
            }
        }
        $argusDecision->billingEntity = $agentDecisionFixtureScope->billingEntity;
        $argusDecision->budgetAccount = $agentDecisionFixtureScope->ownerAccount;
        $argusDecision->setTimeoutSeconds($agentDecisionFixtureRunSettings->timeoutSeconds);
        return $argusDecision;
    }

    /**
     * Scores an answered reply-language call against the case's three expectations (an empty expectation always hits)
     * and adds its row: written and requested answer with their confidence, the resolved reply, the anchor, the latency.
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param AgentDecisionFixtureCase $agentDecisionFixtureCase
     * @param string[] $supportedLanguageCodes
     * @param AgentDecisionFixtureScore $agentDecisionFixtureScore
     * @return void
     */
    protected function scoreReplyLanguageAnswer(
        ArgusAgentTurnStartDecision $argusDecision,
        AgentDecisionFixtureCase $agentDecisionFixtureCase,
        array $supportedLanguageCodes,
        AgentDecisionFixtureScore $agentDecisionFixtureScore
    ): void {
        $anchorLanguageCode = $agentDecisionFixtureCase->getNullableText('conversationLanguage');
        $expectedWritten = strtolower($agentDecisionFixtureCase->getText('expectedWritten'));
        $expectedRequested = strtolower($agentDecisionFixtureCase->getText('expectedRequested'));
        $expectedReply = strtolower($agentDecisionFixtureCase->getText('expectedReply'));
        $writtenAnswer = (string)($argusDecision->writtenLanguageCode ?? '(none)');
        $requestedAnswer = (string)($argusDecision->requestedLanguageCode ?? '(none)');
        $writtenLanguageCodeForChain = AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage($anchorLanguageCode, $argusDecision->ownerWordsThisTurn)
            ? AgentReplyLanguageDecision::ANSWER_UNDECIDABLE
            : $argusDecision->writtenLanguageCode;
        $resolvedReply = $this->resolveReplyLanguage($argusDecision, $writtenLanguageCodeForChain, $anchorLanguageCode, $supportedLanguageCodes);
        $writtenHit = $expectedWritten === '' || $writtenAnswer === $expectedWritten
            || ($writtenLanguageCodeForChain === AgentReplyLanguageDecision::ANSWER_UNDECIDABLE && $expectedWritten === AgentReplyLanguageDecision::ANSWER_UNDECIDABLE);
        $requestedHit = $expectedRequested === '' || $requestedAnswer === $expectedRequested;
        $replyHit = $expectedReply === '' || $resolvedReply === $expectedReply;
        $agentDecisionFixtureScore->recordReplyLanguageAnswer($writtenHit, $requestedHit, $replyHit);
        $latenciesMs = $agentDecisionFixtureScore->latenciesMs;
        $agentDecisionFixtureScore->addTableRow(
            $agentDecisionFixtureCase->getId(),
            $writtenAnswer . ' ' . number_format($argusDecision->writtenConfidence, 2) . ($writtenHit ? '' : ' ✗ exp ' . $expectedWritten),
            $requestedAnswer . ' ' . number_format($argusDecision->requestedConfidence, 2) . ($requestedHit ? '' : ' ✗ exp ' . $expectedRequested),
            $resolvedReply . ($replyHit ? ' ✓' : ' ✗ exp ' . $expectedReply),
            $anchorLanguageCode ?? '—',
            end($latenciesMs) . ' ms'
        );
    }

    /**
     * The language the reply chain resolves for an answered call: the requested answer, the written answer (as the
     * chain reads it), the anchor as decided an hour ago, no directive, the anchor else the default reply language.
     *
     * @param ArgusAgentTurnStartDecision $argusDecision
     * @param string|null $writtenLanguageCodeForChain
     * @param string|null $anchorLanguageCode
     * @param string[] $supportedLanguageCodes
     * @return string
     */
    protected function resolveReplyLanguage(ArgusAgentTurnStartDecision $argusDecision, ?string $writtenLanguageCodeForChain, ?string $anchorLanguageCode, array $supportedLanguageCodes): string
    {
        return AgentReplyLanguageDecision::resolveByRecency(
            $argusDecision->requestedLanguageCode,
            $argusDecision->requestedConfidence,
            $writtenLanguageCodeForChain,
            $argusDecision->writtenConfidence,
            $anchorLanguageCode,
            $anchorLanguageCode !== null ? new DateTime('-1 hour') : null,
            null,
            0.0,
            null,
            $anchorLanguageCode ?? $this->getDefaultReplyLanguageCode(),
            $supportedLanguageCodes
        )->languageCode;
    }

    // ------------------------------------------------------------------ services

    /**
     * The skills service (the application's class, when it replaces it).
     *
     * @return AISkillsService
     */
    protected function getSkillsService(): AISkillsService
    {
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        return $aISkillsService;
    }
}
