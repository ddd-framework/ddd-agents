<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Skills\AISkillMarkedHeading;
use DDD\Domain\AI\Entities\Skills\AISkillOpenSection;
use DDD\Domain\AI\Entities\Skills\AISkillSection;
use DDD\Domain\AI\Entities\Skills\AISkillSections;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A skill body is parsed into its sections with one value object per marked heading and per open section: the
 * marker parses into level, selectors and title, nested sections carry their parent's selectors, a parent resumes as a
 * continuation piece, and the rendered texts stay the same.
 */
class AISkillSectionsParseTest extends UnitTestCase
{
    protected const string SKILL_BODY = "Intro line.\n\n"
        . "## [businessClass=walkIn|hybrid] Walk-in business\n"
        . "Walk-in text.\n"
        . "### [modality=chat] Chat only\n"
        . "Chat text.\n"
        . "### Plain sub heading\n"
        . "Parent continuation.\n"
        . "```\n## [businessClass=mobile] inside a fence\n```\n"
        . "## [businessClass=mobile]\n"
        . "Mobile text without a title.\n"
        . "## [not a marker!] Broken\n"
        . "Core again.\n";

    public function testAMarkedHeadingParsesIntoLevelSelectorsAndTitle(): void
    {
        $aISkillMarkedHeading = AISkillSection::parseMarkedHeading('## [businessClass=walkIn | hybrid&modality=chat] Walk-in business');
        self::assertInstanceOf(AISkillMarkedHeading::class, $aISkillMarkedHeading);
        self::assertSame(2, $aISkillMarkedHeading->level);
        self::assertSame(['businessClass' => 'walkIn|hybrid', 'modality' => 'chat'], $aISkillMarkedHeading->selectors);
        self::assertSame('Walk-in business', $aISkillMarkedHeading->title);
        self::assertNull(AISkillSection::parseMarkedHeading('## Walk-in business'));
        self::assertNull(AISkillSection::parseMarkedHeading('## [not a marker!] Broken'));
    }

    public function testAnOpenSectionEmitsItsFirstPieceWithTheHeadingAndThenContinuations(): void
    {
        $aISkillOpenSection = AISkillOpenSection::forMarkedHeading(
            AISkillMarkedHeading::forLevelSelectorsAndTitle(3, ['modality' => 'chat'], 'Chat only'),
            ['businessClass' => 'walkIn', 'modality' => 'chat']
        );
        self::assertTrue($aISkillOpenSection->hasPieceToEmit(), 'a titled first piece is due even without text');
        $aISkillOpenSection->appendLine('Chat text.');
        $aISkillOpenSection->appendLine('');
        $firstPiece = $aISkillOpenSection->buildPiece(4);
        self::assertSame("### Chat only\nChat text.", $firstPiece->render());
        self::assertSame('businessClass=walkIn&modality=chat', $firstPiece->getId());

        $aISkillOpenSection->startContinuation();
        self::assertSame([], $aISkillOpenSection->lines);
        self::assertFalse($aISkillOpenSection->hasPieceToEmit(), 'an empty continuation is no piece');
        $aISkillOpenSection->appendLine('More.');
        self::assertSame('More.', $aISkillOpenSection->buildPiece(5)->render(), 'a continuation carries no heading');
    }

    public function testTheParsedBodyRendersAsBefore(): void
    {
        $aISkillSections = AISkillSections::fromMarkdown(self::SKILL_BODY);
        self::assertSame(
            ['businessClass=walkIn|hybrid', 'businessClass=walkIn|hybrid&modality=chat', 'businessClass=walkIn|hybrid', 'businessClass=mobile'],
            $aISkillSections->allSectionIds()
        );
        $walkInChatVariants = new AIConversationVariants();
        $walkInChatVariants->put(AIConversationVariant::decided('businessClass', 'walkIn', AIConversationVariant::SOURCE_OWNER));
        $walkInChatVariants->put(AIConversationVariant::decided('modality', 'chat', AIConversationVariant::SOURCE_OWNER));
        self::assertSame(
            "Intro line.\n\n## Walk-in business\nWalk-in text.\n\n### Chat only\nChat text.\n\n### Plain sub heading\nParent continuation.\n"
            . "```\n## [businessClass=mobile] inside a fence\n```\n\n## [not a marker!] Broken\nCore again.",
            $aISkillSections->renderFor($walkInChatVariants)
        );
        self::assertSame(
            "Intro line.\n\n## Walk-in business (only when businessClass=walkIn|hybrid)\nWalk-in text.\n\n"
            . "### Chat only (only when businessClass=walkIn|hybrid and modality=chat)\nChat text.\n\n"
            . "## Only when businessClass=walkIn|hybrid\n### Plain sub heading\nParent continuation.\n"
            . "```\n## [businessClass=mobile] inside a fence\n```\n\n"
            . "## Only when businessClass=mobile\nMobile text without a title.\n\n## [not a marker!] Broken\nCore again.",
            $aISkillSections->renderAll()
        );
    }
}
