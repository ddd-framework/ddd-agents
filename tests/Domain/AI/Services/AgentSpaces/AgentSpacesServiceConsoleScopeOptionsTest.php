<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceConsoleScopeOption;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Without an application's words the agent-spaces service declares no console option of its own and names no space
 * for option values; a declared option carries its name and help text.
 */
class AgentSpacesServiceConsoleScopeOptionsTest extends UnitTestCase
{
    public function testThePackageDeclaresNoConsoleScopeOptionAndResolvesNothing(): void
    {
        $agentSpacesService = $this->getPackageAgentSpacesService();

        self::assertSame(0, $agentSpacesService->getConsoleScopeOptions()->count());
        self::assertNull($agentSpacesService->findAgentSpaceForConsoleScopeOptionValues(['someKey' => '1']));
    }

    public function testAConsoleScopeOptionCarriesItsNameAndHelpText(): void
    {
        $agentSpaceConsoleScopeOption = AgentSpaceConsoleScopeOption::forNameAndDescription('spaceKey', 'The key of the space');

        self::assertSame('spaceKey', $agentSpaceConsoleScopeOption->name);
        self::assertSame('The key of the space', $agentSpaceConsoleScopeOption->description);
    }

    /**
     * The service as the container hands it out. An application that replaces it with a subclass that declares
     * options is not a run of the package alone; the test then reads the package's defaults off the parent methods.
     */
    protected function getPackageAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        if ((new \ReflectionMethod($agentSpacesService, 'getConsoleScopeOptions'))->getDeclaringClass()->getName() !== AgentSpacesService::class) {
            self::markTestSkipped('the application replaces the console scope options of the agent-spaces service');
        }
        return $agentSpacesService;
    }
}
