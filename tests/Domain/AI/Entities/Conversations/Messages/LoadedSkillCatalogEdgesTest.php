<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillCatalogEdges;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillGroups;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The catalog edges of a skill are one value object per skill: the groups of the loaded capabilities keep the loaded
 * tools and sub-skills the edges name, joined with the recorded parent links; a skill without edges keeps only those.
 */
class LoadedSkillCatalogEdgesTest extends UnitTestCase
{
    public function testTheGroupsKeepTheLoadedEdgesAndTheRecordedParents(): void
    {
        $loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Reviews' => ['name' => 'Reviews', 'type' => LoadedCapability::TYPE_SKILL],
            'Reviews.Reply' => ['name' => 'Reviews.Reply', 'type' => LoadedCapability::TYPE_SKILL, 'parentSkillNames' => ['Reviews']],
            'Unknown' => ['name' => 'Unknown', 'type' => LoadedCapability::TYPE_SKILL],
            'listReviews' => ['name' => 'listReviews', 'type' => LoadedCapability::TYPE_TOOL],
            'replyToReview' => ['name' => 'replyToReview', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Reviews.Reply']],
            'legacyTool' => ['name' => 'legacyTool', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Unknown']],
        ]);
        $catalogEdgesBySkillName = [
            'Reviews' => LoadedSkillCatalogEdges::forToolAndSubSkillNames(['listReviews', 'notLoadedTool'], ['Reviews.Reply', 'Reviews.NotLoaded']),
            'Reviews.Reply' => LoadedSkillCatalogEdges::forToolAndSubSkillNames(['listReviews'], []),
        ];

        $loadedSkillGroups = LoadedSkillGroups::fromCatalogEdges($loadedCapabilities, $catalogEdgesBySkillName);

        self::assertSame(['listReviews'], $loadedSkillGroups->getToolNamesOfSkill('Reviews'));
        self::assertSame(['Reviews.Reply'], $loadedSkillGroups->getSubSkillNamesOfSkill('Reviews'));
        self::assertSame(['listReviews', 'replyToReview'], $loadedSkillGroups->getToolNamesOfSkill('Reviews.Reply'));
        self::assertSame(['legacyTool'], $loadedSkillGroups->getToolNamesOfSkill('Unknown'), 'a skill without edges keeps its recorded children');
        self::assertSame(['Reviews', 'Reviews.Reply'], $loadedSkillGroups->getParentSkillNamesOfTool('listReviews'));
    }

    public function testTheFactoryHoldsTheNames(): void
    {
        $loadedSkillCatalogEdges = LoadedSkillCatalogEdges::forToolAndSubSkillNames(['a'], ['B']);
        self::assertSame(['a'], $loadedSkillCatalogEdges->toolNames);
        self::assertSame(['B'], $loadedSkillCatalogEdges->subSkillNames);
    }
}
