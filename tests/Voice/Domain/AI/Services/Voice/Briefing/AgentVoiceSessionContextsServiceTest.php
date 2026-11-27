<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The briefing of a call built from what the agent reads for the chat of the same conversation (character with the
 * persona's names, the owner's address, the standing directives only with the memory store on, what was discussed,
 * the product capabilities), best-effort per block; the language a call is held in; and the notes sent while a call
 * runs, each closed by the call-language clause. The package's space, memory and entitlements services are
 * replaced by doubles; the persona names the agent "Nova".
 */
class AgentVoiceSessionContextsServiceTest extends AgentVoiceIntegrationTestCase
{
    protected BriefingAgentSpacesService $briefingAgentSpacesService;

    protected BriefingAgentMemoryStoreService $briefingAgentMemoryStoreService;

    protected BriefingAIConversationEntitlementsService $briefingEntitlementsService;

    protected const string KEPT_LANGUAGE_CLAUSE = ' Say it in the language you and the caller have been speaking in this call so far — this note is English for the system only; never switch to English because of it.';

    protected const string GERMAN_CLAUSE = ' Say it in German, the language of this call — only if the caller has clearly switched to another language since, use theirs. This note is English for the system only; never switch to English because of it.';

    protected const string BUDGET_END_TEXT = 'The allowance of this account for calls has just run out, so this call has to end now. Say ONE short, friendly sentence: the allowance is used up, you have to end the call here, and they can reach you again once it is available again. No figures, no long apology, no alternatives.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Nova', 'productName' => 'the workbench']);
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => false]);
        $this->briefingAgentSpacesService = new BriefingAgentSpacesService();
        $this->briefingAgentMemoryStoreService = new BriefingAgentMemoryStoreService();
        $this->briefingEntitlementsService = new BriefingAIConversationEntitlementsService();
        $this->replaceService(AgentSpacesService::class, $this->briefingAgentSpacesService);
        $this->replaceService(AgentMemoryStoreService::class, $this->briefingAgentMemoryStoreService);
        $this->replaceService(AIConversationEntitlementsService::class, $this->briefingEntitlementsService);
    }

    protected static function buildConversation(?int $accountId = 42): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->accountId = $accountId;
        $conversation->agentSpaceId = 9;
        $agentSpace = new AgentSpace();
        $agentSpace->id = 9;
        $conversation->agentSpace = $agentSpace;
        $conversation->episodicHistory = 'The menu update.';
        $conversation->messages = new AIConversationMessages();

        return $conversation;
    }

    protected static function buildReplyLanguageDecision(string $languageCode, ?string $decidedBy): AgentReplyLanguageDecision
    {
        $agentReplyLanguageDecision = new AgentReplyLanguageDecision();
        $agentReplyLanguageDecision->languageCode = $languageCode;
        $agentReplyLanguageDecision->decidedBy = $decidedBy;

        return $agentReplyLanguageDecision;
    }

    protected static function withReplyLanguageDecision(AIConversation $conversation, AgentReplyLanguageDecision $agentReplyLanguageDecision): AIConversation
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->replyLanguageDecision = $agentReplyLanguageDecision;
        $conversation->agentWorkingState = $agentWorkingState;

        return $conversation;
    }

    protected static function buildSettings(?string $languageCode, ?bool $mirrorCallerLanguage): AgentVoiceSettings
    {
        $agentVoiceSettings = new AgentVoiceSettings();
        $agentVoiceSettings->languageCode = $languageCode;
        $agentVoiceSettings->mirrorCallerLanguage = $mirrorCallerLanguage;

        return $agentVoiceSettings;
    }

    public function testTheBriefingOfACallIsBuiltFromWhatTheAgentReadsForTheChat(): void
    {
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => true]);
        $this->briefingAgentSpacesService->ownerAddressingInstruction = 'Address the owner as Sam.';
        $this->briefingAgentSpacesService->productNameOfConversation = 'the studio';
        $this->briefingAgentMemoryStoreService->directiveContents = ['Never call before nine.'];
        $this->briefingEntitlementsService->productCapabilities = 'Listings and reviews.';
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->callTask = '  Confirm the opening hours. ';

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), $agentVoiceCall);

        static::assertSame(42, $voiceSessionContext->authenticatedAccountId);
        static::assertSame(AgentVoiceCall::DIRECTION_OUTBOUND, $voiceSessionContext->callDirection);
        static::assertFalse($voiceSessionContext->isWithoutConversation);
        static::assertSame('Confirm the opening hours.', $voiceSessionContext->callTask);
        static::assertSame('The menu update.', $voiceSessionContext->episodicHistory);
        static::assertSame('Open the call in German. From then on answer in the language the caller speaks; while you cannot tell, stay in German.', $voiceSessionContext->languageInstruction);
        static::assertNull($voiceSessionContext->speakingStyle);
        static::assertSame('de', $voiceSessionContext->callLanguageCode);
        static::assertSame('Address the owner as Sam.', $voiceSessionContext->ownerAddressing);
        static::assertSame(['de'], $this->briefingAgentSpacesService->addressingLanguageCodes);
        static::assertSame("\n\n# Standing instructions of the owner — always follow them\n- Never call before nine.", $voiceSessionContext->memoryDirectivesBlock);
        static::assertSame([[42, 9]], $this->briefingAgentMemoryStoreService->readScopes);
        static::assertSame('Listings and reviews.', $voiceSessionContext->productCapabilities);
        static::assertStringStartsWith('You are Nova, the AI assistant inside the studio — the product, never a company.', (string)$voiceSessionContext->characterCore);
    }

    public function testTheCharacterNamesThePersonasProductWhenTheConversationNamesNone(): void
    {
        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertStringStartsWith('You are Nova, the AI assistant inside the workbench — the product, never a company.', (string)$voiceSessionContext->characterCore);
        static::assertStringNotContainsString('{%', (string)$voiceSessionContext->characterCore);
    }

    public function testTheDirectivesAreReadOnlyWithTheMemoryStoreOn(): void
    {
        $this->briefingAgentMemoryStoreService->directiveContents = ['Never call before nine.'];

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertNull($voiceSessionContext->memoryDirectivesBlock);
        static::assertSame([], $this->briefingAgentMemoryStoreService->readScopes);
    }

    public function testAConversationWithoutAnAccountOrWithoutADirectiveHasNoDirectives(): void
    {
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => true]);
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();

        static::assertNull($contextsService->buildVoiceSessionContext(static::buildConversation(null), new AgentVoiceCall())->memoryDirectivesBlock);
        static::assertSame([], $this->briefingAgentMemoryStoreService->readScopes);
        static::assertNull($contextsService->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall())->memoryDirectivesBlock);
        static::assertSame([[42, 9]], $this->briefingAgentMemoryStoreService->readScopes);
    }

    public function testEveryFailingBlockShrinksTheBriefingAndNeverBreaksIt(): void
    {
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => true]);
        $this->briefingAgentSpacesService->failsOwnerAddressing = true;
        $this->briefingAgentMemoryStoreService->failsRead = true;
        $this->briefingEntitlementsService->failsProductAwareness = true;
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();
        $contextsService->failsCharacter = true;
        $contextsService->failsApplicationAdditions = true;
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->callTask = 'Confirm the opening hours.';

        $voiceSessionContext = $contextsService->buildVoiceSessionContext(static::buildConversation(), $agentVoiceCall);

        static::assertNull($voiceSessionContext->characterCore);
        static::assertNull($voiceSessionContext->ownerAddressing);
        static::assertNull($voiceSessionContext->memoryDirectivesBlock);
        static::assertNull($voiceSessionContext->productCapabilities);
        static::assertSame('Confirm the opening hours.', $voiceSessionContext->callTask);
        static::assertSame('de', $voiceSessionContext->callLanguageCode);
        static::assertSame('The menu update.', $voiceSessionContext->episodicHistory);
        static::assertStringContainsString("Your task for this call:\nConfirm the opening hours.", $voiceSessionContext->composeLiveInstructions());
    }

    public function testTheTaskIsTheCallsOwnElseTheFirstOwnerTextOfTheConversation(): void
    {
        $conversation = static::buildConversation();
        $assistantMessage = new AIConversationMessage();
        $assistantMessage->role = AIConversationMessage::ROLE_ASSISTANT;
        $assistantMessage->message = 'Hello.';
        $assistantMessage->indexInConversation = 0;
        $firstOwnerMessage = new AIConversationMessage();
        $firstOwnerMessage->role = AIConversationMessage::ROLE_USER;
        $firstOwnerMessage->message = 'Call me about the invoice.';
        $firstOwnerMessage->indexInConversation = 1;
        $secondOwnerMessage = new AIConversationMessage();
        $secondOwnerMessage->role = AIConversationMessage::ROLE_USER;
        $secondOwnerMessage->message = 'And the menu.';
        $secondOwnerMessage->indexInConversation = 2;
        $conversation->messages->add($secondOwnerMessage, $assistantMessage, $firstOwnerMessage);
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();

        static::assertSame('Call me about the invoice.', $contextsService->buildVoiceSessionContext($conversation, new AgentVoiceCall())->callTask);
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->callTask = '   ';
        static::assertSame('Call me about the invoice.', $contextsService->buildVoiceSessionContext($conversation, $agentVoiceCall)->callTask);
        static::assertNull($contextsService->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall())->callTask);
    }

    public function testAConfiguredLanguageWinsOverTheLanguageOfTheConversation(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['language' => 'fr']));

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertSame('fr', $voiceSessionContext->callLanguageCode);
        static::assertSame('Open the call in French. From then on answer in the language the caller speaks; while you cannot tell, stay in French.', $voiceSessionContext->languageInstruction);
        static::assertSame(['fr'], $this->briefingAgentSpacesService->addressingLanguageCodes);
    }

    public function testStoredSettingsOfAnApplicationGiveTheStyleAndTheFixedLanguage(): void
    {
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();
        $storedVoiceSettings = static::buildSettings('it', false);
        $storedVoiceSettings->speakingStyle = 'calm and matter-of-fact';
        $contextsService->storedVoiceSettings = $storedVoiceSettings;

        $voiceSessionContext = $contextsService->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertSame('calm and matter-of-fact', $voiceSessionContext->speakingStyle);
        static::assertSame('Speak Italian for the whole call, regardless of which language the caller uses. Only words in Italian are addressed to you: speech in another language is the caller talking to other people in the room — do not answer it, do not translate or summarise it, do not hand it on as a request; stay silent until you are addressed in Italian again.', $voiceSessionContext->languageInstruction);
        static::assertSame('it', $voiceSessionContext->callLanguageCode);
        static::assertStringContainsString(
            "\nSpeak Italian for the whole call, regardless of which language the caller uses. Only words in Italian are addressed to you: speech in another language is the caller talking to other people in the room — do not answer it, do not translate or summarise it, do not hand it on as a request; stay silent until you are addressed in Italian again. Speaking style set by the owner: calm and matter-of-fact.\n\n",
            $voiceSessionContext->composeLiveInstructions()
        );
    }

    public function testTheEffectiveSettingsOfTheBaseAreTheConfiguredDefault(): void
    {
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();

        $effectiveVoiceSettings = $contextsService->findEffectiveVoiceSettingsOfCall(new AgentVoiceCall(), static::buildConversation());
        static::assertInstanceOf(AgentVoiceSettings::class, $effectiveVoiceSettings);
        static::assertSame([null, 'de', true, null], [$effectiveVoiceSettings->voiceId, $effectiveVoiceSettings->languageCode, $effectiveVoiceSettings->mirrorCallerLanguage, $effectiveVoiceSettings->speakingStyle]);
        $this->briefingAgentSpacesService->userLanguageCode = '  ';
        static::assertSame(Translatable::getDefaultLanguageCode(), $contextsService->findEffectiveVoiceSettingsOfCall(new AgentVoiceCall(), static::buildConversation())?->languageCode);
        static::assertSame(Translatable::getDefaultLanguageCode(), $contextsService->findEffectiveVoiceSettingsOfCall(new AgentVoiceCall())?->languageCode);
    }

    /** @return array<string, array{?AgentReplyLanguageDecision, ?AgentVoiceSettings, bool, ?string}> */
    public static function provideCallLanguages(): array
    {
        return [
            'settings held in one language win over the decision' => [static::buildReplyLanguageDecision('de', AgentReplyLanguageDecision::DECIDED_BY_ANCHOR), static::buildSettings('fr', false), true, 'fr'],
            'an applied decision wins over following settings' => [static::buildReplyLanguageDecision('de-AT', AgentReplyLanguageDecision::DECIDED_BY_ANCHOR), static::buildSettings('fr', true), true, 'de'],
            'a decision of the decision model applies' => [static::buildReplyLanguageDecision('it', 'jev'), static::buildSettings('fr', null), true, 'it'],
            'a decision that is not applied is ignored' => [static::buildReplyLanguageDecision('de', 'shadow'), static::buildSettings('pt_BR', true), true, 'pt'],
            'no decision: the settings' => [null, static::buildSettings('FR', true), true, 'fr'],
            'no decision and no settings: the conversation' => [null, null, true, 'es'],
            'without the configured fallback: nothing' => [null, null, false, null],
        ];
    }

    #[DataProvider('provideCallLanguages')]
    public function testThePrecedenceOfTheCallLanguage(?AgentReplyLanguageDecision $agentReplyLanguageDecision, ?AgentVoiceSettings $effectiveVoiceSettings, bool $withConfiguredFallback, ?string $expectedCallLanguageCode): void
    {
        $this->briefingAgentSpacesService->userLanguageCode = 'es-ES';
        $conversation = static::buildConversation();
        if ($agentReplyLanguageDecision !== null) {
            static::withReplyLanguageDecision($conversation, $agentReplyLanguageDecision);
        }

        static::assertSame(
            $expectedCallLanguageCode,
            (new FixedCallLanguageAgentVoiceSessionContextsService())->resolveCallLanguageCode($conversation, $effectiveVoiceSettings, $withConfiguredFallback)
        );
    }

    public function testACallWithoutAConversationSpeaksTheLanguageOfItsSettings(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['language' => 'de']));
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->accountId = 42;
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContextWithoutConversation($agentVoiceCall);

        static::assertTrue($voiceSessionContext->isWithoutConversation);
        static::assertSame(42, $voiceSessionContext->authenticatedAccountId);
        static::assertSame(AgentVoiceCall::DIRECTION_OUTBOUND, $voiceSessionContext->callDirection);
        static::assertSame('de', $voiceSessionContext->callLanguageCode);
        static::assertSame('Open the call in German. From then on answer in the language the caller speaks; while you cannot tell, stay in German.', $voiceSessionContext->languageInstruction);
        static::assertSame(
            'You are Nova, the owner\'s digital assistant. The owner reached you from inside the application and is signed in. Open the call yourself: greet them briefly and ask what this is about.',
            $voiceSessionContext->composeLiveInstructions()
        );
    }

    public function testACallWithoutAConversationHasNoStoredLanguage(): void
    {
        static::assertNull((new FixedCallLanguageAgentVoiceSessionContextsService())->findCallLanguageCodeOfVoiceCall(new AgentVoiceCall()));
        static::assertSame(static::KEPT_LANGUAGE_CLAUSE, (new \DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService())->buildCallLanguageClause(new AgentVoiceCall()));
    }

    /** @return array<string, array{?string, bool, string}> */
    public static function provideCallLanguageClauses(): array
    {
        return [
            'German' => ['de', false, ' Say it in German, the language of this call — only if the caller has clearly switched to another language since, use theirs. This note is English for the system only; never switch to English because of it.'],
            'English' => ['en', false, ' Say it in English, the language of this call — only if the caller has clearly switched to another language since, use theirs.'],
            'no stored language' => [null, false, ' Say it in the language you and the caller have been speaking in this call so far — this note is English for the system only; never switch to English because of it.'],
            'an empty stored language' => ['', false, ' Say it in the language you and the caller have been speaking in this call so far — this note is English for the system only; never switch to English because of it.'],
            'a failing read' => ['de', true, ' Say it in the language you and the caller have been speaking in this call so far — this note is English for the system only; never switch to English because of it.'],
        ];
    }

    #[DataProvider('provideCallLanguageClauses')]
    public function testTheClauseOfEveryNoteNamesTheCallLanguageOrKeepsIt(?string $storedCallLanguageCode, bool $failsCallLanguageRead, string $expectedClause): void
    {
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();
        $contextsService->storedCallLanguageCode = $storedCallLanguageCode;
        $contextsService->failsCallLanguageRead = $failsCallLanguageRead;

        static::assertSame($expectedClause, $contextsService->buildCallLanguageClause(new AgentVoiceCall()));
    }

    public function testTheFourNotesSentWhileACallRunsComeFromThePromptFiles(): void
    {
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();
        $contextsService->storedCallLanguageCode = 'de';
        $agentVoiceCall = new AgentVoiceCall();

        static::assertSame('The line has gone quiet. Ask briefly whether the caller is still there and what you can do for them — one sentence, then listen.' . static::GERMAN_CLAUSE, $contextsService->buildSilenceCheckInstruction($agentVoiceCall));
        static::assertSame('The caller has not answered your last question. Ask it once more, shorter — one sentence — then listen.' . static::GERMAN_CLAUSE, $contextsService->buildQuestionRepeatInstruction($agentVoiceCall));
        static::assertSame('The line has been silent for a long time. Say one short goodbye sentence and stop — the call ends now.' . static::GERMAN_CLAUSE, $contextsService->buildGoodbyeInstruction($agentVoiceCall));
        static::assertSame('The line stays silent. Say one short goodbye sentence and stop — the call ends now.' . static::GERMAN_CLAUSE, $contextsService->buildGoodbyeInstruction($agentVoiceCall, afterSilenceChecks: true));
        static::assertSame(static::BUDGET_END_TEXT . static::GERMAN_CLAUSE, $contextsService->buildBudgetEndInstruction($agentVoiceCall));
    }

    /** @return array<string, array{AgentVoiceCallRefusal, bool}> */
    public static function provideRefusals(): array
    {
        return [
            'no AI budget' => [AgentVoiceCallRefusal::forNoAiBudget(), true],
            'the daily limit' => [AgentVoiceCallRefusal::forDailyLimitReached(), true],
            'the feature is not available' => [AgentVoiceCallRefusal::forFeatureNotAvailable(), false],
            'an application\'s own code' => [AgentVoiceCallRefusal::forCode('account_suspended'), false],
        ];
    }

    #[DataProvider('provideRefusals')]
    public function testARefusedCallSaysTheBudgetEndOnlyForALimit(AgentVoiceCallRefusal $agentVoiceCallRefusal, bool $expectsBudgetEnd): void
    {
        $contextsService = new FixedCallLanguageAgentVoiceSessionContextsService();
        $contextsService->storedCallLanguageCode = 'de';

        $refusedCallGoodbye = $contextsService->buildRefusedCallGoodbyeInstruction(new AgentVoiceCall(), $agentVoiceCallRefusal);

        static::assertSame(
            ($expectsBudgetEnd
                ? static::BUDGET_END_TEXT
                : 'This call cannot go on and has to end now. Say ONE short, friendly sentence: you have to end the call here, and they can reach you again later. No reason, no long apology, no alternatives.')
            . static::GERMAN_CLAUSE,
            $refusedCallGoodbye
        );
    }
}
