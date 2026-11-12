<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Discovery;

use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFolderClassFinder;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Infrastructure\Services\DDDService;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The source-roots discovery contract over fixture roots (`tests/Fixtures/Discovery/`): an application root and two further
 * module roots beside this package's own `src/`. The registries read every root; an application class that extends a
 * core widget replaces it before the duplicate check; a widget in a subfolder and a signal source of a further module
 * are found; an unrelated duplicate is a build error (thrown under tests).
 */
class AgentSourceRootsDiscoveryTest extends TestCase
{
    protected const string FIXTURE_DIRECTORY = __DIR__ . '/../../../../../Fixtures/Discovery';
    protected const string FIXTURE_NAMESPACE = 'DDD\\Tests\\Agents\\Fixtures\\Discovery\\';

    protected function tearDown(): void
    {
        $this->getAgentSourceRootsService()->replaceSourceRoots(null);
        parent::tearDown();
    }

    public function testTheBuiltRootsStartWithTheApplicationAndContainThisPackageOnce(): void
    {
        $agentSourceRootsService = $this->getAgentSourceRootsService();
        $agentSourceRootsService->replaceSourceRoots(null);
        $agentSourceRoots = $agentSourceRootsService->getSourceRoots();
        $firstSourceRoot = $agentSourceRoots->first();
        self::assertNotNull($firstSourceRoot);
        self::assertTrue($firstSourceRoot->isApplication);
        self::assertSame(DDDService::APP_ROOT_NAMESPACE . '\\', $firstSourceRoot->rootNamespace);
        $packageSourceDirectory = realpath($agentSourceRootsService->getPackageSourceDirectory());
        $packageRootCount = 0;
        foreach ($agentSourceRoots->getElements() as $agentSourceRoot) {
            $packageRootCount += $agentSourceRoot->directory === $packageSourceDirectory ? 1 : 0;
            if ($agentSourceRoot !== $firstSourceRoot) {
                self::assertFalse($agentSourceRoot->isApplication);
                self::assertSame('DDD\\', $agentSourceRoot->rootNamespace);
            }
        }
        self::assertSame(1, $packageRootCount);
    }

    public function testAClassNameIsMappedThroughTheDeepestRootContainingTheFile(): void
    {
        $agentSourceRoots = $this->buildFixtureRoots('ModuleB');
        $classFilePath = realpath(self::FIXTURE_DIRECTORY . '/ModuleB/Domain/Demo/Entities/AI/Conversations/Widgets/Grouped/DemoNestedConversationWidget.php');
        self::assertSame(
            self::FIXTURE_NAMESPACE . 'ModuleB\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\Grouped\\DemoNestedConversationWidget',
            $agentSourceRoots->findClassNameForFilePath((string)$classFilePath)
        );
        self::assertNull($agentSourceRoots->findClassNameForFilePath('/no/such/root/Foo.php'));
    }

    public function testTheWidgetRegistryReadsEveryRootAndTheApplicationOverrideWins(): void
    {
        $this->getAgentSourceRootsService()->replaceSourceRoots($this->buildFixtureRoots('ModuleB'));
        $widgetClassesByWidgetType = ConversationWidgetClasses::buildWidgetClassesByWidgetType(...ConversationWidgetClasses::findWidgetClassNamesInWidgetFolders());
        self::assertSame(self::FIXTURE_NAMESPACE . 'App\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\QuickRepliesConversationWidget', $widgetClassesByWidgetType['QuickReplies']);
        self::assertSame(self::FIXTURE_NAMESPACE . 'ModuleB\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\DemoExtraConversationWidget', $widgetClassesByWidgetType['DemoExtra']);
        self::assertSame(self::FIXTURE_NAMESPACE . 'ModuleB\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\Grouped\\DemoNestedConversationWidget', $widgetClassesByWidgetType['DemoNested']);
        $widgetTypes = array_keys($widgetClassesByWidgetType);
        $sortedWidgetTypes = $widgetTypes;
        sort($sortedWidgetTypes);
        self::assertSame($sortedWidgetTypes, $widgetTypes);
    }

    public function testTheOverrideWinsWhicheverOfTheTwoClassesIsFoundFirst(): void
    {
        $coreWidgetClass = \DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget::class;
        $overridingWidgetClass = self::FIXTURE_NAMESPACE . 'App\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\QuickRepliesConversationWidget';
        self::assertTrue(class_exists($overridingWidgetClass));
        self::assertSame($overridingWidgetClass, ConversationWidgetClasses::buildWidgetClassesByWidgetType($coreWidgetClass, $overridingWidgetClass)['QuickReplies']);
        self::assertSame($overridingWidgetClass, ConversationWidgetClasses::buildWidgetClassesByWidgetType($overridingWidgetClass, $coreWidgetClass)['QuickReplies']);
        self::assertSame($overridingWidgetClass, ComposedViewFolderClassFinder::resolveOverridingClassName($coreWidgetClass, $overridingWidgetClass));
        self::assertNull(ComposedViewFolderClassFinder::resolveOverridingClassName(
            self::FIXTURE_NAMESPACE . 'ModuleB\\Domain\\Demo\\Entities\\AI\\Conversations\\Widgets\\DemoExtraConversationWidget',
            self::FIXTURE_NAMESPACE . 'ModuleC\\Domain\\Other\\Entities\\AI\\Conversations\\Widgets\\DemoExtraConversationWidget'
        ));
    }

    public function testAnUnrelatedDuplicateWidgetTypeIsABuildError(): void
    {
        $this->getAgentSourceRootsService()->replaceSourceRoots($this->buildFixtureRoots('ModuleB', 'ModuleC'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two widget classes declare the WIDGET_TYPE "DemoExtra"');
        ConversationWidgetClasses::buildWidgetClassesByWidgetType(...ConversationWidgetClasses::findWidgetClassNamesInWidgetFolders());
    }

    public function testASignalSourceOfAFurtherModuleIsFoundAfterTheCoreEnum(): void
    {
        $this->getAgentSourceRootsService()->replaceSourceRoots($this->buildFixtureRoots('ModuleB'));
        $enumClassNames = ConversationSignalSources::findSignalSourceEnumClassNames();
        $demoEnumClass = self::FIXTURE_NAMESPACE . 'ModuleB\\Domain\\Demo\\Entities\\AI\\Conversations\\SignalSources\\DemoConversationSignalSource';
        self::assertContains($demoEnumClass, $enumClassNames);
        $signalSourcesByValue = ConversationSignalSources::buildSignalSourcesByValue(...$enumClassNames);
        self::assertSame($demoEnumClass, $signalSourcesByValue['DEMO_FIXTURE_SIGNAL']::class);
    }

    /** The application fixture root, the named module fixture roots, then this package's `src/`. */
    protected function buildFixtureRoots(string ...$moduleFixtureNames): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_NAMESPACE . 'App', self::FIXTURE_DIRECTORY . '/App', true));
        foreach ($moduleFixtureNames as $moduleFixtureName) {
            $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_NAMESPACE . $moduleFixtureName, self::FIXTURE_DIRECTORY . '/' . $moduleFixtureName, false));
        }
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory('DDD', $this->getAgentSourceRootsService()->getPackageSourceDirectory(), false));
        return $agentSourceRoots;
    }

    protected function getAgentSourceRootsService(): AgentSourceRootsService
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $agentSourceRootsService;
    }
}
