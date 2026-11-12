<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The sites of the decision layer are the application's to extend: without an application the module's own list, with
 * one the list its class at the same path declares — for the canonical spelling and the panels' summary.
 */
class AgentDecisionModelSettingsSitesOfAnApplicationTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testWithoutAnApplicationTheSitesAreTheModulesOwn(): void
    {
        self::assertSame(AgentDecisionModelSettings::class, AgentDecisionModelSettings::getSettingsClassOfTheApplication());
        self::assertSame(AgentDecisionModelSettings::SITES, AgentDecisionModelSettings::getSites());
        self::assertNull(AgentDecisionModelSettings::canonicalSite('checkOfTheApplication'));
        self::assertSame('replyLanguage', AgentDecisionModelSettings::canonicalSite(' REPLYLANGUAGE '));

        $settings = AgentDecisionModelSettings::fromValues('on', 'checkOfTheApplication:shadow', null, null);
        self::assertSame(AgentDecisionModelSettings::SITES, array_keys($settings->siteModesSummary()));
    }

    public function testTheModuleListsNoSiteOfAnApplicationsSetup(): void
    {
        foreach (['serveModelAnswer', 'ownerWordsKind', 'nationalReach', 'descriptionAudience'] as $siteOfAnApplication) {
            self::assertNotContains($siteOfAnApplication, AgentDecisionModelSettings::SITES);
        }
    }

    public function testAnApplicationDeclaresItsOwnSitesInItsClassAtTheSamePath(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentDecisionModelSettings::class] = AgentDecisionModelSettingsOfAnApplicationWithItsOwnSite::class;

        self::assertSame(AgentDecisionModelSettingsOfAnApplicationWithItsOwnSite::class, AgentDecisionModelSettings::getSettingsClassOfTheApplication());
        self::assertSame(['replyLanguage', 'checkOfTheApplication', 'turnClosing'], AgentDecisionModelSettings::getSites());
        self::assertSame('checkOfTheApplication', AgentDecisionModelSettings::canonicalSite('CheckOfTheApplication'));
        self::assertNull(AgentDecisionModelSettings::canonicalSite('stallRecovery'), 'a site the application does not list is unknown');

        $settings = AgentDecisionModelSettings::fromValues('on', 'checkOfTheApplication:shadow,replyLanguage:on', null, null);
        self::assertSame(
            ['replyLanguage' => 'on', 'checkOfTheApplication' => 'shadow', 'turnClosing' => 'off'],
            $settings->siteModesSummary()
        );
    }
}
