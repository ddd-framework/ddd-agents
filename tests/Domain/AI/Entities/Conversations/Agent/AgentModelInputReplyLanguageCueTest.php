<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The reply-language tail cue: the language is named IN WORDS as the first clause (a code alone lost against long
 * tool results in another language); with NO decision the wording is the golden string below; with a language
 * MEASURED from the owner's words the cue says so, and names itself the winner over the prefix's configured
 * language only when the two differ.
 */
class AgentModelInputReplyLanguageCueTest extends UnitTestCase
{
    /** The wording for a named language without a measured decision — the golden string. */
    protected const string GOLDEN_NAMED_CUE = '[SIGNAL:reply-language] Write your ENTIRE reply in German ("de"). The owner writes German.'
        . ' The pages, listings and data you just read may be in another language — that changes nothing: their content'
        . ' is quoted, your sentences are German. It STAYS German: a single owner message in another language, workspace'
        . ' data in the workspace\'s language, tool results and system texts never move it; only the owner\'s explicit'
        . ' request to switch does. EVERY owner-visible sentence: answers, status updates.';

    public function testWithoutADecisionTheCueIsByteIdenticalToTheGoldenWording(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->userLanguageCode = 'de';
        self::assertSame(self::GOLDEN_NAMED_CUE, $agentContext->buildReplyLanguageTailCue());

        // a configured code alone (no decision source) changes nothing either
        $agentContext->configuredLanguageCode = 'de';
        self::assertSame(self::GOLDEN_NAMED_CUE, $agentContext->buildReplyLanguageTailCue());
    }

    public function testWithoutANamedLanguageTheGenericConstantIsUnchanged(): void
    {
        $agentContext = new AgentModelInput();
        self::assertSame(AgentModelInput::REPLY_LANGUAGE_TAIL_CUE, $agentContext->buildReplyLanguageTailCue());
    }

    public function testAnAnchorOrConfigSourcedDecisionKeepsTheGoldenWording(): void
    {
        foreach ([AgentReplyLanguageDecision::SOURCE_ANCHOR, AgentReplyLanguageDecision::SOURCE_DIRECTIVE, AgentReplyLanguageDecision::SOURCE_CONFIG] as $source) {
            $agentContext = new AgentModelInput();
            $agentContext->userLanguageCode = 'de';
            $agentContext->configuredLanguageCode = 'en';
            $agentContext->replyLanguageSource = $source;
            self::assertSame(self::GOLDEN_NAMED_CUE, $agentContext->buildReplyLanguageTailCue(), $source);
        }
    }

    public function testAMeasuredLanguageSaysSoAndSupersedesADifferingConfiguredOne(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->userLanguageCode = 'ro';
        $agentContext->configuredLanguageCode = 'de';
        $agentContext->replyLanguageSource = AgentReplyLanguageDecision::SOURCE_WRITTEN;
        $cue = $agentContext->buildReplyLanguageTailCue();
        self::assertStringStartsWith('[SIGNAL:reply-language] Write your ENTIRE reply in Romanian ("ro"). The owner writes Romanian (measured from the owner\'s own words). The pages, listings and data you just read may be in another language — that changes nothing: their content is quoted, your sentences are Romanian. This supersedes any other language named in your instructions. It STAYS Romanian', $cue);
        self::assertStringEndsWith('EVERY owner-visible sentence: answers, status updates.', $cue);
    }

    public function testAMeasuredLanguageEqualToTheConfiguredOneDoesNotClaimToSupersede(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->userLanguageCode = 'de';
        $agentContext->configuredLanguageCode = 'de';
        $agentContext->replyLanguageSource = AgentReplyLanguageDecision::SOURCE_REQUEST;
        $cue = $agentContext->buildReplyLanguageTailCue();
        self::assertStringContainsString('(measured from the owner\'s own words)', $cue);
        self::assertStringNotContainsString('supersedes', $cue);
    }
}
