<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Infrastructure\Libs\Config;
use DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use LogicException;
use ReflectionProperty;

/**
 * The module ships the command a child runs (`app:ai-agent:execute-tool-call`): a tool call runs in a process of its
 * own once the application declared its console script in `config/app/AI/agentToolCallChildProcess.php`; a file that
 * names no command gets the module's. An application's file replaces the module's file whole, so the tests that are
 * about such a file register a real application config root holding it (searched before the module's root).
 */
class AgentToolCallForkJoinServiceChildProcessCommandTest extends UnitTestCase
{
    /** @var string The config file of the application, below its config root */
    public const string APPLICATION_CONFIG_FILE_BELOW_ROOT = 'AI/agentToolCallChildProcess.php';

    /** @var array<string, mixed>|null the config values read so far, restored after every test */
    protected ?array $configTreeBefore = null;

    /** @var string[] the application config roots before the test, restored after it */
    protected array $applicationConfigRootsBefore = [];

    /** @var string|null the application config root a test created; removed after it */
    protected ?string $applicationConfigRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? $configTree->getValue() : null;
        $this->applicationConfigRootsBefore = (new ReflectionProperty(Config::class, 'appRootDirectories'))->getValue();
    }

    protected function tearDown(): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $this->configTreeBefore ?? []);
        (new ReflectionProperty(Config::class, 'appRootDirectories'))->setValue(null, $this->applicationConfigRootsBefore);
        if ($this->applicationConfigRoot !== null) {
            @unlink($this->applicationConfigRoot . '/' . self::APPLICATION_CONFIG_FILE_BELOW_ROOT);
            @rmdir($this->applicationConfigRoot . '/AI');
            @rmdir($this->applicationConfigRoot);
            $this->applicationConfigRoot = null;
        }
        parent::tearDown();
    }

    public function testThePackageNamesItsCommandButNoConsoleScriptAndForksNothing(): void
    {
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);

        self::assertNull($agentToolCallForkJoinService->getChildProcessConsoleScript());
        self::assertSame('app:ai-agent:execute-tool-call', $agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertFalse($agentToolCallForkJoinService->isChildProcessCommandDeclared());
        self::assertSame([], $agentToolCallForkJoinService->selectForkableToolCalls([new ToolCall(), new ToolCall()], $conversation));
    }

    public function testStartingChildrenWithoutADeclaredCommandIsRefused(): void
    {
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);

        $this->expectException(LogicException::class);
        $agentToolCallForkJoinService->startChildren(new AgentToolCallFork(), $conversation);
    }

    public function testTheCommandOfTheApplicationIsReadFromItsConfigFile(): void
    {
        $this->declareChildProcessCommandOfTheApplication(['consoleScript' => '/bin/console-of-the-shop', 'commandName' => ' shop:agent:execute-tool-call ']);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertSame('bin/console-of-the-shop', $agentToolCallForkJoinService->getChildProcessConsoleScript());
        self::assertSame('shop:agent:execute-tool-call', $agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertTrue($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    public function testAnApplicationFileWithoutACommandGetsTheModulesCommandFromTheCode(): void
    {
        $this->writeConfigFileOfTheApplication(['consoleScript' => 'bin/console-of-the-shop']);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertSame('bin/console-of-the-shop', $agentToolCallForkJoinService->getChildProcessConsoleScript(), 'the application\'s file is the one read');
        self::assertNull(Config::get(AgentToolCallForkJoinService::CHILD_PROCESS_CONFIG_NAME . '.commandName'), 'the application\'s file names no command');
        self::assertSame(AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME, $agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertTrue($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    public function testAnApplicationFileWithANullCommandGetsTheModulesCommandToo(): void
    {
        $this->writeConfigFileOfTheApplication(['consoleScript' => 'bin/console-of-the-shop', 'commandName' => null]);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertSame(AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME, $agentToolCallForkJoinService->getChildProcessCommandName());
        self::assertTrue($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    public function testAnApplicationThatDeclaresNoConsoleScriptForksNothing(): void
    {
        $this->writeConfigFileOfTheApplication(['consoleScript' => null, 'commandName' => 'shop:agent:execute-tool-call']);
        /** @var AgentToolCallForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(AgentToolCallForkJoinService::class);

        self::assertFalse($agentToolCallForkJoinService->isChildProcessCommandDeclared());
    }

    public function testTheDefaultIsTheNameOfTheModulesCommand(): void
    {
        self::assertSame(AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME, (new AgentExecuteToolCallCommand())->getName());
    }

    /**
     * Writes the application's config file into a fresh application config root, registers the root (application
     * roots are searched before module roots, a newer one first) and drops the values already read, so the next
     * {@see Config::get()} searches the roots and reads exactly this file. A key the file lacks then stays a miss.
     *
     * @param array<string, string|null> $configValuesOfTheApplication
     */
    protected function writeConfigFileOfTheApplication(array $configValuesOfTheApplication): void
    {
        $this->applicationConfigRoot = sys_get_temp_dir() . '/agent-tool-call-child-config-' . bin2hex(random_bytes(6));
        mkdir($this->applicationConfigRoot . '/AI', 0777, true);
        file_put_contents(
            $this->applicationConfigRoot . '/' . self::APPLICATION_CONFIG_FILE_BELOW_ROOT,
            "<?php\n\nreturn " . var_export($configValuesOfTheApplication, true) . ";\n"
        );
        Config::addConfigDirectory($this->applicationConfigRoot);
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? $configTree->getValue() : [];
        unset($configValues['AI']['agentToolCallChildProcess']);
        $configTree->setValue(null, $configValues);
    }

    /**
     * Writes the application's declaration where {@see Config::get()} reads it first: the values it has already read.
     *
     * @param array<string, string|null> $childProcessCommandOfTheApplication
     */
    protected function declareChildProcessCommandOfTheApplication(array $childProcessCommandOfTheApplication): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? $configTree->getValue() : [];
        $configValues['AI']['agentToolCallChildProcess'] = $childProcessCommandOfTheApplication;
        $configTree->setValue(null, $configValues);
    }
}
