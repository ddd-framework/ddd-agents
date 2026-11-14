<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Config;

use DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService;
use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The package resolves its config keys and prompts without an application: every key and prompt the core reads has
 * a default file under the package's `config/app/AI/`. The files are read from the package directory itself — an application's files at the same paths would
 * win through `Config`, which is exactly what the application's own characterization test proves.
 */
class PackageDefaultConfigAndPromptsTest extends TestCase
{
    protected const string PACKAGE_CONFIG_DIRECTORY = __DIR__ . '/../../config/app';

    /** The config files the core reads (key under `AI.` => the keys the core reads from it). */
    protected const array CONFIG_FILES_WITH_KEYS = [
        'supervisor' => ['enabled', 'modelName', 'reasoningEffort', 'stepReasoningEffort'],
        'decisionModel' => ['mode', 'sites', 'turnClosingMode', 'timeoutSeconds', 'breakerSeconds'],
        'contextRetention' => ['unloadGranularity', 'skillLoadingStrategy', 'rebuildEpochOnSituationChange', 'stateChangeDelivery', 'appendOnlyReachableToolSetCeilingTokens', 'previousReasoningCapTokens'],
        'agentEgress' => ['transferStallAbort', 'liteLlmEndpoint'],
        'agentToolCallChildProcess' => ['consoleScript', 'commandName'],
        'agentApplicationNames' => ['traceLinePrefix', 'consoleAssistantLabel', 'furtherEnvironmentVariableNames'],
        'agentPersona' => ['agentName', 'productName'],
        'agentApplicationWording' => ['billingEntityName', 'agentSpaceName', 'agentSpaceContextLabel', 'agentSpaceContextStateKind', 'agentSpaceContextSubjectName', 'ownerConsentSameTargetExamples', 'ownerConsentSameTargetWrittenDifferentlyExample', 'ownerConsentDifferentTargetExamples'],
        'evalOutboundRecipients' => ['allowedEmailDomains', 'allowedEmailAddresses', 'allowedPhoneNumbersE164'],
        'skillNamespaceGroups' => [],
    ];

    /** The further names a fixture application declares for the core's environment variables. */
    protected const array FURTHER_ENVIRONMENT_VARIABLE_NAMES_OF_THE_APPLICATION = [
        AgentEnvironmentVariables::UNLOAD_GRANULARITY => 'SHOP_UNLOAD_GRANULARITY',
        AgentEnvironmentVariables::SUB_SKILL_TOOL_DISCOVERY => 'SHOP_SUB_SKILL_TOOL_DISCOVERY',
        AgentEnvironmentVariables::EGRESS => 'SHOP_AGENT_EGRESS',
        AgentEnvironmentVariables::TRACE => 'SHOP_TRACE',
    ];

    /** The placeholders the agent repo sets on the agentic prompt; the package's agentic file must carry the persona ones. */
    protected const array AGENTIC_PERSONA_PLACEHOLDERS = ['{%AGENT_NAME%}', '{%PRODUCT_NAME%}', '{%AGENT_CHARACTER%}', '{%AGENT_SPACE_ID%}', '{%AGENT_SPACE_CONTEXT%}'];

    public function testEveryConfigFileTheCoreReadsShipsWithItsKeys(): void
    {
        foreach (self::CONFIG_FILES_WITH_KEYS as $configFileName => $expectedKeys) {
            $configFilePath = self::PACKAGE_CONFIG_DIRECTORY . '/AI/' . $configFileName . '.php';
            self::assertFileExists($configFilePath);
            $configValue = require $configFilePath;
            self::assertIsArray($configValue, $configFileName);
            foreach ($expectedKeys as $expectedKey) {
                self::assertArrayHasKey($expectedKey, $configValue, $configFileName . '.' . $expectedKey);
            }
        }
    }

    public function testThePackageDefaultsAreTheNeutralOnes(): void
    {
        $agentPersona = require self::PACKAGE_CONFIG_DIRECTORY . '/AI/agentPersona.php';
        self::assertSame(['agentName' => ArgusAIAgentConversation::DEFAULT_AGENT_NAME, 'productName' => ArgusAIAgentConversation::DEFAULT_PRODUCT_NAME], $agentPersona);
        $agentEgress = require self::PACKAGE_CONFIG_DIRECTORY . '/AI/agentEgress.php';
        self::assertNull($agentEgress['liteLlmEndpoint']);
        self::assertSame(0, $agentEgress['transferStallAbort']['seconds']);
        $agentToolCallChildProcess = require self::PACKAGE_CONFIG_DIRECTORY . '/AI/agentToolCallChildProcess.php';
        // no console script: every tool call runs inline until the application names one; the command is the module's
        self::assertSame(['consoleScript' => null, 'commandName' => AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME], $agentToolCallChildProcess);
        $decisionModel = require self::PACKAGE_CONFIG_DIRECTORY . '/AI/decisionModel.php';
        self::assertSame('off', $decisionModel['mode']);
        $supervisor = require self::PACKAGE_CONFIG_DIRECTORY . '/AI/supervisor.php';
        self::assertFalse($supervisor['enabled']);
        self::assertArrayNotHasKey('policyClassForConversationsWithoutAPurpose', $supervisor);
    }

    public function testEveryPromptTheCoreNamesShipsAsAFileExceptTheDeprecatedQualityPrompts(): void
    {
        foreach ($this->findCorePromptNames() as $promptName) {
            self::assertFileExists($this->buildPromptFilePath($promptName), $promptName);
            self::assertNotSame('', trim((string)file_get_contents($this->buildPromptFilePath($promptName))), $promptName);
        }
        self::assertFileExists($this->buildPromptFilePath(substr(SupervisorCheckpointService::CONFIG_KEY_OPERATING_PROMPT, strlen(AIPromptsService::CONFIG_PROMPTS_PREFIX))));
    }

    public function testTheAgenticPromptCarriesThePersonaPlaceholders(): void
    {
        $agenticPromptText = (string)file_get_contents($this->buildPromptFilePath(AgentAIPromptNames::APP_AI_AGENT_AGENTIC));
        foreach (self::AGENTIC_PERSONA_PLACEHOLDERS as $placeholder) {
            self::assertStringContainsString($placeholder, $agenticPromptText);
        }
        self::assertStringContainsString('{%AGENT_NAME%}', (string)file_get_contents($this->buildPromptFilePath(AgentAIPromptNames::APP_AI_AGENT_CHARACTER)));
    }

    public function testTheCharacterTextFillsBothPersonaPlaceholders(): void
    {
        self::assertSame(
            'You are Nova inside Shop — Shop, not a company.',
            ArgusAIAgentConversation::buildAgentCharacterText('You are {%AGENT_NAME%} inside {%PRODUCT_NAME%} — {%PRODUCT_NAME%}, not a company.', 'Shop', 'Nova')
        );
    }

    public function testWithoutAnApplicationDeclarationEveryNameIsTheCoresOwn(): void
    {
        self::assertSame(['AGENT_TRACE'], AgentEnvironmentVariables::getNamesInReadOrder(AgentEnvironmentVariables::TRACE));
        self::assertSame(AgentEnvironmentVariables::UNLOAD_GRANULARITY, ContextRetentionSettings::ENV_UNLOAD_GRANULARITY);
        self::assertSame(AgentEnvironmentVariables::SUB_SKILL_TOOL_DISCOVERY, SubSkillToolDiscovery::ENVIRONMENT_VARIABLE);
        self::assertSame(AgentApplicationNames::DEFAULT_TRACE_LINE_PREFIX, AgentApplicationNames::getTraceLinePrefix());
        self::assertSame(AgentApplicationNames::DEFAULT_CONSOLE_ASSISTANT_LABEL, AgentApplicationNames::getConsoleAssistantLabel());
        foreach (array_keys(self::FURTHER_ENVIRONMENT_VARIABLE_NAMES_OF_THE_APPLICATION) as $environmentVariableName) {
            self::assertStringStartsWith('AGENT_', $environmentVariableName);
            self::assertNull(AgentApplicationNames::getFurtherEnvironmentVariableName($environmentVariableName));
        }
    }

    public function testTheNamesAnApplicationDeclaresAreReadAndItsEnvironmentNameComesAfterTheCoresOwn(): void
    {
        $environmentBefore = $this->snapshotEnvironment();
        $configValuesBefore = $this->declareNamesOfTheApplication([
            'traceLinePrefix' => '[shop:trace]',
            'consoleAssistantLabel' => 'shop',
            'furtherEnvironmentVariableNames' => self::FURTHER_ENVIRONMENT_VARIABLE_NAMES_OF_THE_APPLICATION,
        ]);
        try {
            $this->clearEnvironment();
            self::assertSame('[shop:trace]', AgentApplicationNames::getTraceLinePrefix());
            self::assertSame('shop', AgentApplicationNames::getConsoleAssistantLabel());
            self::assertSame(['AGENT_TRACE', 'SHOP_TRACE'], AgentEnvironmentVariables::getNamesInReadOrder(AgentEnvironmentVariables::TRACE));
            self::assertSame(['SOMETHING_ELSE'], AgentEnvironmentVariables::getNamesInReadOrder('SOMETHING_ELSE'));

            $_ENV['SHOP_SUB_SKILL_TOOL_DISCOVERY'] = SubSkillToolDiscovery::MODE_OFF;
            self::assertSame(SubSkillToolDiscovery::MODE_OFF, SubSkillToolDiscovery::resolveMode());
            $_ENV[SubSkillToolDiscovery::ENVIRONMENT_VARIABLE] = SubSkillToolDiscovery::MODE_BOTH;
            self::assertSame(SubSkillToolDiscovery::MODE_BOTH, SubSkillToolDiscovery::resolveMode(), 'the core\'s own name wins');

            $_SERVER['SHOP_UNLOAD_GRANULARITY'] = ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL;
            self::assertSame(ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL, ContextRetentionSettings::resolveDefaultUnloadGranularity());
            $_SERVER[ContextRetentionSettings::ENV_UNLOAD_GRANULARITY] = ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL;
            self::assertSame(ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL, ContextRetentionSettings::resolveDefaultUnloadGranularity(), 'the core\'s own name wins');

            $_SERVER['SHOP_TRACE'] = '1';
            self::assertSame('1', AgentEnvironmentVariables::readRawProcessValue(AgentEnvironmentVariables::TRACE));
            $_SERVER[AgentEnvironmentVariables::TRACE] = '0';
            self::assertSame('0', AgentEnvironmentVariables::readRawProcessValue(AgentEnvironmentVariables::TRACE), 'the core\'s own name wins');
        } finally {
            $this->clearEnvironment();
            $this->restoreEnvironment($environmentBefore);
            $this->restoreConfigValues($configValuesBefore);
        }
    }

    /**
     * Writes the application's declaration where {@see Config::get()} reads it first: the values it has already read.
     *
     * @param array<string, mixed> $namesOfTheApplication
     * @return array<string, mixed> the values Config held before
     */
    protected function declareNamesOfTheApplication(array $namesOfTheApplication): array
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues = $configValuesBefore;
        $configValues['AI']['agentApplicationNames'] = $namesOfTheApplication;
        $configTree->setValue(null, $configValues);
        return $configValuesBefore;
    }

    /** @param array<string, mixed> $configValuesBefore */
    protected function restoreConfigValues(array $configValuesBefore): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $configValuesBefore);
    }

    /** @return string[] the prompt names of the core (the deprecated application prompts left out) */
    protected function findCorePromptNames(): array
    {
        $promptNames = [];
        foreach ((new ReflectionClass(AgentAIPromptNames::class))->getReflectionConstants() as $reflectionConstant) {
            if (str_contains((string)$reflectionConstant->getDocComment(), '@deprecated')) {
                continue;
            }
            $promptNames[] = (string)$reflectionConstant->getValue();
        }
        return $promptNames;
    }

    protected function buildPromptFilePath(string $promptName): string
    {
        return self::PACKAGE_CONFIG_DIRECTORY . '/AI/Prompts/' . str_replace('.', '/', $promptName) . '.md';
    }

    /** @return array<string, array<string, mixed>> */
    protected function snapshotEnvironment(): array
    {
        $environmentBefore = ['_ENV' => [], '_SERVER' => []];
        foreach (self::FURTHER_ENVIRONMENT_VARIABLE_NAMES_OF_THE_APPLICATION as $environmentVariableName => $furtherName) {
            foreach ([$environmentVariableName, $furtherName] as $nameToSave) {
                if (array_key_exists($nameToSave, $_ENV)) {
                    $environmentBefore['_ENV'][$nameToSave] = $_ENV[$nameToSave];
                }
                if (array_key_exists($nameToSave, $_SERVER)) {
                    $environmentBefore['_SERVER'][$nameToSave] = $_SERVER[$nameToSave];
                }
            }
        }
        return $environmentBefore;
    }

    protected function clearEnvironment(): void
    {
        foreach (self::FURTHER_ENVIRONMENT_VARIABLE_NAMES_OF_THE_APPLICATION as $environmentVariableName => $furtherName) {
            unset($_ENV[$environmentVariableName], $_ENV[$furtherName], $_SERVER[$environmentVariableName], $_SERVER[$furtherName]);
        }
    }

    /** @param array<string, array<string, mixed>> $environmentBefore */
    protected function restoreEnvironment(array $environmentBefore): void
    {
        foreach ($environmentBefore['_ENV'] as $environmentVariableName => $value) {
            $_ENV[$environmentVariableName] = $value;
        }
        foreach ($environmentBefore['_SERVER'] as $environmentVariableName => $value) {
            $_SERVER[$environmentVariableName] = $value;
        }
    }
}
