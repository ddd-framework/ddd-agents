<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Skills\AISkillFrontmatterAndBody;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A `.md` config splits into one object holding its frontmatter fields and its body; without a frontmatter block the
 * fields are empty and the body is the whole input.
 */
class AISkillFrontmatterAndBodyTest extends UnitTestCase
{
    public function testAFrontmatterBlockSplitsIntoFieldsAndBody(): void
    {
        $aISkillFrontmatterAndBody = AISkillsService::splitFrontmatter("---\ndescription: Reply to reviews\nnonEvictable: true\n---\n# Body\nText.\n");
        self::assertInstanceOf(AISkillFrontmatterAndBody::class, $aISkillFrontmatterAndBody);
        self::assertSame(['description' => 'Reply to reviews', 'nonEvictable' => true], $aISkillFrontmatterAndBody->frontmatter);
        self::assertSame("# Body\nText.\n", $aISkillFrontmatterAndBody->body);
    }

    public function testATextWithoutFrontmatterIsAllBody(): void
    {
        $aISkillFrontmatterAndBody = AISkillsService::splitFrontmatter("# Body only\n");
        self::assertSame([], $aISkillFrontmatterAndBody->frontmatter);
        self::assertSame("# Body only\n", $aISkillFrontmatterAndBody->body);

        $scalarFrontmatter = AISkillsService::splitFrontmatter("---\njust text\n---\nBody");
        self::assertSame([], $scalarFrontmatter->frontmatter, 'a frontmatter that is no map has no fields');
        self::assertSame('Body', $scalarFrontmatter->body);
    }
}
