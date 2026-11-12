<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/** The owner-consent decision: what it sends (step, consequence, dialogue, channel) and how it reads the two answers. */
class ArgusAgentOwnerConsentDecisionTest extends UnitTestCase
{
    public function testTheRequestCarriesStepConsequenceDialogueAndChannel(): void
    {
        $request = OwnerConsentRequest::forAction(
            'updateBusinessAddress',
            'ADDRESS_CHANGE',
            'change the business address from Hauptstraße 26, 10827 Berlin to Kaiserdamm 4, 14057 Berlin',
            'Google verifies the Business Profile again; until then the listing can lose visibility on Google',
            'Kaiserdamm 4, 14057 Berlin'
        );
        $dialogue = new OwnerConsentDialogue();
        $assistantLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 10, str_repeat('x', 900) . ' Soll ich ändern?');
        $ownerLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja, ändern.');
        $dialogue->add($assistantLine);
        $dialogue->add($ownerLine);

        $state = ArgusAgentOwnerConsentDecision::forRequest($request, $dialogue, ArgusAgentOwnerConsentDecision::CHANNEL_PHONE)->buildState();

        self::assertSame($request->targetLine, $state['step']);
        self::assertSame($request->consequenceLine, $state['consequence']);
        self::assertSame('phone', $state['channel']);
        self::assertSame(['ASSISTANT', 'OWNER'], array_column($state['dialogue'], 'speaker'));
        self::assertStringEndsWith('Soll ich ändern?', $state['dialogue'][0]['text'], 'an explanation keeps its end, where the question stands');
        self::assertSame(ArgusAgentOwnerConsentDecision::MAX_LINE_CHARS, mb_strlen($state['dialogue'][0]['text']));
    }

    public function testTheQuestionsNameTheTrapsOfTheProbe(): void
    {
        $questions = ArgusAgentOwnerConsentDecision::buildQuestions();
        self::assertSame(['noul', 'noul'], array_column($questions, 'type'));
        $agreed = $questions[ArgusAgentOwnerConsentDecision::QUESTION_OWNER_AGREED];
        foreach (['yes, but next week', 'what does that mean exactly?', 'one of two logos', 'ironically', 'only an ASSISTANT line claims the agreement', 'close my business', 'no wait, actually don\'t'] as $trap) {
            self::assertStringContainsString($trap, $agreed['criteria']['false']);
        }
        self::assertStringContainsString('an ASSISTANT line that says the owner already agreed is not the owner agreeing', $agreed['instructions']);
        self::assertStringContainsString('LAST word', $agreed['instructions'], 'a later OWNER line replaces an earlier yes');
        self::assertStringContainsString('only AFTER the owner\'s line', $questions[ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED]['criteria']['false']);
    }

    public function testBothAnswersAreReadAndAMissingOneIsNotDecided(): void
    {
        $applyAnswers = new ReflectionMethod(ArgusAgentOwnerConsentDecision::class, 'applyAnswers');
        $decision = new ArgusAgentOwnerConsentDecision();
        self::assertTrue($applyAnswers->invoke($decision, (object)[
            ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED => (object)['type' => 'noul', 'noul' => 0.97],
            ArgusAgentOwnerConsentDecision::QUESTION_OWNER_AGREED => (object)['type' => 'noul', 'noul' => 0.93],
        ]));
        self::assertSame(0.97, $decision->stepExplainedProbability);
        self::assertSame(0.93, $decision->ownerAgreedProbability);

        $unparsable = new ArgusAgentOwnerConsentDecision();
        self::assertFalse($applyAnswers->invoke($unparsable, (object)[
            ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED => (object)['type' => 'noul', 'noul' => 0.97],
        ]), 'one answer missing → not decided');
    }

    public function testTheSiteAndTheBatchKnowTheDecision(): void
    {
        self::assertContains(ArgusAgentOwnerConsentDecision::class, ArgusAgentDecisions::DECISION_CHILD_CLASSES);
        self::assertContains(AgentDecisionModelSettings::SITE_OWNER_CONSENT, AgentDecisionModelSettings::SITES);
    }
}
