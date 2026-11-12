<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\SemanticSearchService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Pure-logic tests for the shared search primitive: the excerpt windowing (what the UI/agent sees) and the
 * Reciprocal-Rank-Fusion of the two candidate rankings. No DB/embedding — the retrieval seam is exercised by the
 * integration path; here we pin the deterministic, framework-free parts.
 */
class SemanticSearchServiceTest extends UnitTestCase
{
    protected function service(): SemanticSearchService
    {
        return $this->instantiateWithoutConstructor(SemanticSearchService::class);
    }

    public function testFullTextWhenExcerptCharsZero(): void
    {
        $text = 'The whole message is returned verbatim for the agent path.';
        $this->assertSame($text, $this->service()->windowExcerpt($text, 'agent', 0));
    }

    public function testFullTextWhenShorterThanWindow(): void
    {
        $text = 'short message';
        $this->assertSame($text, $this->service()->windowExcerpt($text, 'message', 200));
    }

    public function testWindowsAroundTheFirstQueryTermWithEllipses(): void
    {
        $text = str_repeat('alpha ', 40) . 'CAMPAIGN for the bakery ' . str_repeat('omega ', 40);
        $excerpt = $this->service()->windowExcerpt($text, 'campaign bakery', 60);

        $this->assertStringContainsStringIgnoringCase('campaign', $excerpt);
        $this->assertStringStartsWith('…', $excerpt);   // trimmed on the left
        $this->assertStringEndsWith('…', $excerpt);      // trimmed on the right
        $this->assertLessThanOrEqual(62, mb_strlen($excerpt));
    }

    public function testHeadWindowWhenNoQueryTermMatches(): void
    {
        $text = str_repeat('lorem ipsum dolor ', 30);
        $excerpt = $this->service()->windowExcerpt($text, 'zzz nonexistent', 50);

        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringStartsWith('lorem', $excerpt);
    }

    public function testReciprocalRankFusionRewardsAgreementAcrossLists(): void
    {
        // id 7 is high in BOTH lists → must rank first; id 2 only in the direct list; id 9 only in the node list.
        $fused = $this->invokeMethod($this->service(), 'reciprocalRankFusion', [[[2, 7, 5], [9, 7, 4]]]);

        $this->assertSame(7, $fused[0]); // appears near the top of both → highest fused score
        $this->assertContains(2, $fused);
        $this->assertContains(9, $fused);
    }
}
