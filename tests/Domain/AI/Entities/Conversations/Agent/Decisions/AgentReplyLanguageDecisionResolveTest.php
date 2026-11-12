<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageResolution;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The RECENCY chain of the reply-language decision — every row of the scenario matrix that
 * is loop logic rather than a model answer. Pure: no container, no model.
 */
class AgentReplyLanguageDecisionResolveTest extends UnitTestCase
{
    protected const array CODES = ['de', 'en', 'ro', 'fr'];

    /**
     * @return AgentReplyLanguageResolution
     */
    protected function resolve(
        ?string $requested = null, float $requestedConfidence = 0.0,
        ?string $written = null, float $writtenConfidence = 0.0,
        ?string $anchor = null, ?string $anchorAt = null,
        ?string $directive = null, float $directiveConfidence = 0.0, ?string $directiveAt = null,
        string $configured = 'de'
    ): AgentReplyLanguageResolution {
        return AgentReplyLanguageDecision::resolveByRecency(
            $requested, $requestedConfidence, $written, $writtenConfidence,
            $anchor, $anchorAt !== null ? new DateTime($anchorAt) : null,
            $directive, $directiveConfidence, $directiveAt !== null ? new DateTime($directiveAt) : null,
            $configured, self::CODES
        );
    }

    /**
     * @param string $expectedLanguageCode
     * @param string $expectedSource
     * @param AgentReplyLanguageResolution $replyLanguageResolution
     * @return void
     */
    protected static function assertResolvedTo(string $expectedLanguageCode, string $expectedSource, AgentReplyLanguageResolution $replyLanguageResolution): void
    {
        self::assertSame($expectedLanguageCode, $replyLanguageResolution->languageCode);
        self::assertSame($expectedSource, $replyLanguageResolution->source);
    }

    public function testRow1TurnOneNoOwnerWordsFallsBackToTheConfiguredLanguage(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(configured: 'de'));
    }

    public function testRow2TheProductionDefectOwnerWritesRomanianFromWordOne(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_WRITTEN, $this->resolve(written: 'ro', writtenConfidence: 0.99, configured: 'de'));
    }

    public function testRow4ThinTurnWithNothingEstablishedUsesTheConfiguredLanguage(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, configured: 'de'));
    }

    public function testRow5ThinTurnKeepsTheConversationAnchorOverTheConfig(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_ANCHOR, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, anchor: 'ro', anchorAt: '2026-09-22 10:00:00', configured: 'de'));
    }

    public function testRow6AnExplicitRequestWrittenInGermanYieldsRomanian(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_REQUEST, $this->resolve(requested: 'ro', requestedConfidence: 0.99, written: 'de', writtenConfidence: 0.96, configured: 'de'));
    }

    public function testRow7TheWrittenLanguageIsYoungerThanAStandingDirective(): void
    {
        // the rule: what the owner writes NOW beats a directive from an earlier session
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_WRITTEN, $this->resolve(written: 'de', writtenConfidence: 1.0, directive: 'en', directiveConfidence: 1.0, directiveAt: '2026-08-01 09:00:00', configured: 'de'));
    }

    public function testRow8ARequestInThisTurnBeatsTheDirective(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_REQUEST, $this->resolve(requested: 'de', requestedConfidence: 1.0, written: 'de', writtenConfidence: 1.0, directive: 'en', directiveConfidence: 1.0, directiveAt: '2026-08-01 09:00:00'));
    }

    public function testRow9ThinTurnWithADirectiveAndNoAnchorUsesTheDirective(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_DIRECTIVE, $this->resolve(written: 'undecidable', writtenConfidence: 0.92, directive: 'ro', directiveConfidence: 1.0, directiveAt: '2026-08-01 09:00:00', configured: 'de'));
    }

    public function testRow10OneForeignOwnerMessageFlipsByRecency(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_WRITTEN, $this->resolve(written: 'ro', writtenConfidence: 0.99, anchor: 'de', anchorAt: '2026-09-22 10:00:00', configured: 'de'));
    }

    public function testRow23ADirectiveYoungerThanTheAnchorWinsAThinTurn(): void
    {
        self::assertResolvedTo('en', AgentReplyLanguageDecision::SOURCE_DIRECTIVE, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, anchor: 'de', anchorAt: '2026-09-22 10:00:00', directive: 'en', directiveConfidence: 1.0, directiveAt: '2026-09-22 10:30:00'));
    }

    public function testRow24AnAnchorYoungerThanTheDirectiveWinsAThinTurn(): void
    {
        self::assertResolvedTo('ro', AgentReplyLanguageDecision::SOURCE_ANCHOR, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, anchor: 'ro', anchorAt: '2026-09-22 10:30:00', directive: 'en', directiveConfidence: 1.0, directiveAt: '2026-08-22 10:00:00'));
    }

    public function testRow22AConflictingDirectiveIsSkipped(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_WRITTEN, $this->resolve(written: 'de', writtenConfidence: 1.0, directive: 'conflicting', directiveConfidence: 0.98));
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, directive: 'conflicting', directiveConfidence: 0.98, configured: 'de'));
    }

    public function testRow12AnUnsupportedRequestedLanguageIsNotAppliedPendingOwnerDecisionD6(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_WRITTEN, $this->resolve(requested: 'other', requestedConfidence: 1.0, written: 'de', writtenConfidence: 0.6 + 0.4));
    }

    public function testALowConfidenceAnswerIsAnAbstention(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(written: 'ro', writtenConfidence: 0.6, configured: 'de'));
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(requested: 'ro', requestedConfidence: 0.5, written: 'undecidable', writtenConfidence: 1.0, configured: 'de'));
    }

    public function testACodeOutsideTheClosedSetIsAnAbstention(): void
    {
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_CONFIG, $this->resolve(written: 'hu', writtenConfidence: 1.0, configured: 'de'));
    }

    public function testAMissingInstantRanksAsTheOlderOfAnchorAndDirective(): void
    {
        self::assertResolvedTo('en', AgentReplyLanguageDecision::SOURCE_DIRECTIVE, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, anchor: 'de', anchorAt: null, directive: 'en', directiveConfidence: 1.0, directiveAt: '2026-08-01 09:00:00'));
        self::assertResolvedTo('de', AgentReplyLanguageDecision::SOURCE_ANCHOR, $this->resolve(written: 'undecidable', writtenConfidence: 1.0, anchor: 'de', anchorAt: '2026-08-01 09:00:00', directive: 'en', directiveConfidence: 1.0, directiveAt: null));
    }

    public function testOnlyADecisionModelStampIsApplied(): void
    {
        $decision = new AgentReplyLanguageDecision();
        $decision->languageCode = 'ro';
        $decision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_SHADOW;
        self::assertFalse($decision->isApplied());
        $decision->decidedBy = AgentReplyLanguageDecision::FALLBACK_BREAKER;
        self::assertFalse($decision->isApplied());
        $decision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_DECISION_MODEL;
        self::assertTrue($decision->isApplied());
        $decision->languageCode = null;
        self::assertFalse($decision->isApplied());
    }

    public function testAPlatformStampOfAUserAbsentRunIsAppliedAndCarriesTheConfiguredLanguage(): void
    {
        $decision = AgentReplyLanguageDecision::forUserAbsentRun(0, 'de', new DateTime('2026-10-08 06:00:23'));
        self::assertTrue($decision->isApplied());
        self::assertSame('de', $decision->languageCode);
        self::assertSame(AgentReplyLanguageDecision::SOURCE_CONFIG, $decision->source);
        self::assertSame(AgentReplyLanguageDecision::DECIDED_BY_PLATFORM, $decision->decidedBy);
        self::assertSame(0, $decision->ownerMessageIndex);
        self::assertSame('2026-10-08 06:00:23', $decision->decidedAt);
        self::assertFalse($decision->isMeasuredFromOwnerWords());
        self::assertNull($decision->writtenLanguageCode);
        $roundTrip = new AgentReplyLanguageDecision();
        $roundTrip->mapFromRepository($decision->mapToRepository());
        self::assertTrue($roundTrip->isApplied());
        self::assertSame(AgentReplyLanguageDecision::DECIDED_BY_PLATFORM, $roundTrip->decidedBy);
    }

    public function testAFirstMessageOfOneBareTokenIsInconclusiveWrittenEvidence(): void
    {
        self::assertTrue(AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage(null, 'rankings'));
        self::assertTrue(AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage(null, '  Hola  '));
        self::assertFalse(AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage(null, 'Hi, can you help me?'));
        self::assertFalse(AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage('de', 'rankings'), 'with an anchor the context question decides');
    }

    public function testRepositoryRoundTripKeepsEveryField(): void
    {
        $decision = new AgentReplyLanguageDecision();
        $decision->languageCode = 'ro';
        $decision->source = AgentReplyLanguageDecision::SOURCE_WRITTEN;
        $decision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_DECISION_MODEL;
        $decision->ownerMessageIndex = 7;
        $decision->decidedAt = '2026-09-22 10:00:00';
        $decision->writtenLanguageCode = 'ro';
        $decision->writtenConfidence = 0.99;
        $decision->requestedLanguageCode = 'none';
        $decision->requestedConfidence = 0.86;
        $decision->configuredLanguageCode = 'de';
        $decision->modelVersion = 'typesafe/jev-1.13-20260917';
        $restored = new AgentReplyLanguageDecision();
        $restored->mapFromRepository(json_decode((string)json_encode($decision->mapToRepository()), true));
        self::assertSame($decision->mapToRepository(), $restored->mapToRepository());
        self::assertSame(7, $restored->ownerMessageIndex);
        self::assertTrue($restored->isApplied());
    }

    public function testAnAnchorCarriedThroughAModelOutageIsApplied(): void
    {
        $decision = new AgentReplyLanguageDecision();
        $decision->languageCode = 'ro';
        $decision->source = AgentReplyLanguageDecision::SOURCE_ANCHOR;
        $decision->decidedBy = AgentReplyLanguageDecision::FALLBACK_TRANSPORT;
        self::assertFalse($decision->isApplied(), 'a bare fallback stamp is a record only');
        $decision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_ANCHOR;
        self::assertTrue($decision->isApplied(), 'the conversation\'s own earlier decision needs no model answer');
        $decision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_SHADOW;
        self::assertFalse($decision->isApplied());
    }
}
