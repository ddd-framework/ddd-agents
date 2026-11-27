<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachHandler;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;
use DDD\Domain\AI\Services\Installation\AgentVoiceChannelInstallationCheckService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\Agents\AgentsModule;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\TestContainerRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceTestConfiguration;
use FilesystemIterator;
use Ratchet\Client\Connector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

/**
 * The session worker of the voice channel is the one part of the package that needs a WebSocket library, which the
 * package only suggests. The module gates it ({@see AgentsModule::getNamespacesRequiringClasses()}): only the files
 * below the gated namespaces name a class of the library; with the library the worker is a service and a switched-on
 * voice channel can run; in an installation without it (a child process whose class loader refuses the library, the
 * voice channel switched on) the container still compiles, the worker and its attach handler are no services, the one
 * place answers that voice cannot run and each of its askers follows it, the provider adapter and the control request
 * of a hang-up stay usable, `app:agent:check-installation` names the installation without failing, and
 * `app:agent:voice-samples:generate` answers that the library is missing.
 */
class VoiceWithoutSessionWorkerLibraryTest extends UnitTestCase
{
    /** @var array<string, mixed>|null The config values before the test declared the voice channel */
    protected ?array $configValuesBeforeTheTest = null;

    protected function tearDown(): void
    {
        if ($this->configValuesBeforeTheTest !== null) {
            AgentVoiceTestConfiguration::restoreConfigValues($this->configValuesBeforeTheTest);
        }
        parent::tearDown();
    }

    public function testTheModuleGatesTheTwoSessionWorkerNamespacesOnAClassOfTheLibrary(): void
    {
        self::assertSame(
            [
                'DDD\\Domain\\AI\\MessageHandlers\\Voice\\SessionWorker\\' => Connector::class,
                'DDD\\Domain\\AI\\Services\\Voice\\SessionWorker\\' => Connector::class,
                'DDD\\Domain\\AI\\Services\\Connections\\Adapters\\Slack\\SocketMode\\' => Connector::class,
                'DDD\\Domain\\AI\\Services\\Connections\\Adapters\\Google\\' => 'Google\\Client',
            ],
            AgentsModule::getNamespacesRequiringClasses()
        );
        foreach ([AgentLiveVoiceSessionsService::class, VoiceLiveSessionAttachHandler::class, VoiceLiveSessionAttachMessage::class] as $gatedClassName) {
            $gatingNamespaces = array_filter(
                array_keys(AgentsModule::getNamespacesRequiringClasses()),
                static fn (string $gatedNamespace): bool => str_starts_with($gatedClassName, $gatedNamespace)
            );
            self::assertCount(1, $gatingNamespaces, $gatedClassName);
        }
    }

    public function testOnlyTheFilesBelowTheGatedNamespacesNameAClassOfTheLibrary(): void
    {
        $sourceDirectory = (string)realpath(dirname(__DIR__, 2) . '/src');
        $gatedDirectories = [];
        foreach (array_keys(AgentsModule::getNamespacesRequiringClasses()) as $gatedNamespace) {
            $gatedDirectories[] = $sourceDirectory . '/' . str_replace('\\', '/', substr($gatedNamespace, strlen('DDD\\')));
        }
        $filesNamingTheLibrary = [];
        $gatedFilesNamingTheLibrary = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('~^use (Ratchet|React)\\\\~m', (string)file_get_contents($file->getPathname())) !== 1) {
                continue;
            }
            $relativeFile = substr($file->getPathname(), strlen($sourceDirectory) + 1);
            $isBelowAGatedDirectory = false;
            foreach ($gatedDirectories as $gatedDirectory) {
                $isBelowAGatedDirectory = $isBelowAGatedDirectory || str_starts_with($file->getPathname(), $gatedDirectory);
            }
            if ($isBelowAGatedDirectory) {
                $gatedFilesNamingTheLibrary[] = $relativeFile;
                continue;
            }
            $filesNamingTheLibrary[] = $relativeFile;
        }
        // the module names the class it gates on; nothing loads the library through a class name
        self::assertSame(['Modules/Agents/AgentsModule.php'], $filesNamingTheLibrary, 'a file that imports the library sits below a gated namespace');
        sort($gatedFilesNamingTheLibrary);
        self::assertSame([
            'Domain/AI/Services/Connections/Adapters/Slack/SocketMode/SlackSocketModeRunner.php',
            'Domain/AI/Services/Voice/SessionWorker/AgentLiveVoiceSessionsService.php',
            'Domain/AI/Services/Voice/SessionWorker/Samples/LiveVoiceSampleSession.php',
            'Domain/AI/Services/Voice/SessionWorker/Samples/LiveVoiceSampleSynthesizer.php',
            'Domain/AI/Services/Voice/SessionWorker/Samples/OriginlessWebSocketConnector.php',
        ], $gatedFilesNamingTheLibrary);
    }

    public function testWithTheLibraryTheSessionWorkerIsAServiceAndASwitchedOnVoiceChannelCanRun(): void
    {
        self::assertTrue(class_exists(AgentsModule::VOICE_SESSION_WORKER_LIBRARY_CLASS), 'the package requires the library for its own suite (require-dev)');
        self::assertTrue(TestContainerRegistry::get()->has(AgentLiveVoiceSessionsService::class));
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);

        self::assertFalse($agentVoiceCallAdmissionService->canVoiceRunHere(), 'switched off is the default');
        self::assertFalse(AgentVoiceCallsMCPPackage::isAvailable());

        $this->configValuesBeforeTheTest = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());

        self::assertTrue($agentVoiceCallAdmissionService->canVoiceRunHere());
        self::assertFalse($agentVoiceCallAdmissionService->isVoiceSwitchedOnWithoutSessionWorkerLibrary());
        self::assertTrue(AgentVoiceCallsMCPPackage::isAvailable());
        $voiceSkillBinding = [AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class]];
        self::assertSame($voiceSkillBinding, AISkill::withoutUnavailablePackages($voiceSkillBinding));
    }

    public function testWithoutTheLibraryTheContainerCompilesAndASwitchedOnVoiceChannelReadsAsUnavailable(): void
    {
        $factsProcess = $this->runScriptWithoutTheLibrary('print-voice-facts.php');

        self::assertSame(0, $factsProcess->getExitCode(), $factsProcess->getOutput() . $factsProcess->getErrorOutput());
        self::assertSame(
            [
                'libraryClassExists' => false,
                'sessionWorkerIsAService' => false,
                'attachHandlerIsOfAnInactivePart' => true,
                'attachMessageClassIsLoadable' => true,
                'callsServiceIsAService' => true,
                'canVoiceRunHere' => false,
                'isVoiceSwitchedOnWithoutSessionWorkerLibrary' => true,
                'refusalCodeOfACallStart' => 'feature_not_available',
                'voiceChannelProviderIsAvailable' => false,
                'activeVoiceChannelProviderClassName' => null,
                'toolPackageIsAvailable' => false,
                'voiceSkillBindingWithoutUnavailablePackages' => [],
                'providerAdapterClassNameOfTheDefaultProvider' => OpenAILiveVoiceProviderAdapter::class,
                'providerAdapterIsTheShippedOne' => true,
                'sessionCreationResponseClassIsLoadable' => true,
            ],
            json_decode($factsProcess->getOutput(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testWithoutTheLibraryTheAttachHandlerHandlesNoMessageAndTheRingDeadlineHandlerStillDoes(): void
    {
        $messengerProcess = $this->runScriptWithoutTheLibrary('console.php', 'debug:messenger', '--no-ansi');

        self::assertSame(0, $messengerProcess->getExitCode(), $messengerProcess->getOutput() . $messengerProcess->getErrorOutput());
        self::assertStringNotContainsString('VoiceLiveSessionAttachHandler', $messengerProcess->getOutput());
        self::assertStringContainsString('handled by DDD\\Domain\\AI\\MessageHandlers\\Voice\\AgentVoiceRingDeadlineHandler', $messengerProcess->getOutput());
    }

    public function testWithoutTheLibraryTheInstallationCheckNamesTheSwitchedOnVoiceChannelAndDoesNotFail(): void
    {
        $checkProcess = $this->runScriptWithoutTheLibrary('console.php', 'app:agent:check-installation', '--format=json', '--no-ansi');

        self::assertSame(0, $checkProcess->getExitCode(), $checkProcess->getOutput() . $checkProcess->getErrorOutput());
        $installationCheck = json_decode($checkProcess->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $voiceChannelFindings = array_values(array_filter(
            $installationCheck['findings'],
            static fn (array $finding): bool => $finding['checkName'] === AgentVoiceChannelInstallationCheckService::CHECK_NAME
        ));
        self::assertCount(1, $voiceChannelFindings);
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $voiceChannelFindings[0]['severity']);
        self::assertSame(AgentVoiceChannelInstallationCheckService::SUBJECT_VOICE_SWITCH, $voiceChannelFindings[0]['subject']);
        self::assertStringStartsWith('Voice is switched on but the WebSocket library is not installed', $voiceChannelFindings[0]['message']);
        self::assertStringContainsString('composer require ratchet/pawl react/event-loop', $voiceChannelFindings[0]['hint']);
        self::assertSame(0, $installationCheck['blocking']);
    }

    public function testWithoutTheLibraryTheVoiceSamplesCommandAnswersThatTheLibraryIsMissing(): void
    {
        $samplesProcess = $this->runScriptWithoutTheLibrary('console.php', 'app:agent:voice-samples:generate', '--voice=flitz', '--language=de', '--no-ansi');

        self::assertSame(1, $samplesProcess->getExitCode(), $samplesProcess->getOutput() . $samplesProcess->getErrorOutput());
        self::assertStringContainsString('composer require ratchet/pawl react/event-loop', $samplesProcess->getOutput());
    }

    protected function runScriptWithoutTheLibrary(string $scriptName, string ...$scriptArguments): Process
    {
        $packageDirectory = dirname(__DIR__, 2);
        $vendorDirectory = getenv(PackageTestAutoloader::VENDOR_DIRECTORY_ENV) ?: $packageDirectory . '/vendor';
        $scriptProcess = new Process(
            [PHP_BINARY, $packageDirectory . '/tests/Fixtures/VoiceWithoutSessionWorkerLibrary/' . $scriptName, ...$scriptArguments],
            $packageDirectory,
            [PackageTestAutoloader::VENDOR_DIRECTORY_ENV => $vendorDirectory]
        );
        $scriptProcess->setTimeout(300);
        $scriptProcess->run();
        return $scriptProcess;
    }
}
