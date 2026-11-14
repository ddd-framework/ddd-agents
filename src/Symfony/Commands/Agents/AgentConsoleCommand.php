<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The base of the module's console commands. It owns what every agent command repeated on its own:
 *
 * - the account a run works as: the configured default account for console work
 *   ({@see usesDefaultAccountForConsoleWork()}), acting as another account for one step ({@see actAsAccount()}), and
 *   the account that was authenticated before the run, which is restored when the run ends;
 * - lifting the entity rights for the run ({@see liftsEntityRightsRestrictions()}), restored exactly once when the run
 *   ends, also after an exception;
 * - `--ignore-budget` ({@see configureIgnoreBudgetOption()}): the budget-bypass grant of `mgamadeus/ddd-ai` for the
 *   process; the spend is still booked;
 * - the usage envelope of the run ({@see getUsageEnvelopeScope()}) or of one step ({@see openUsageEnvelope()} /
 *   {@see closeUsageEnvelope()} in try/finally), opened through `AIUsageEnvelopesService` of `mgamadeus/ddd-ai`;
 * - the progress reporter a service reports its lines to ({@see getProgressReporter()});
 * - the space of the run: `--agentSpaceId` plus the options the application's agent-spaces service declares
 *   ({@see configureAgentSpaceOptions()}, {@see resolveAgentSpaceFromInput()}), and the space's owner account
 *   ({@see findOwnerAccountOfAgentSpace()}).
 *
 * A subclass declares its options in `configure()` (calling the `configure…Option(s)()` methods it needs), states what
 * the run needs by overriding the switch methods, and does its work in {@see executeConsoleWork()}. An application
 * replaces a command of the module by a subclass outside `DDD\Symfony\Commands\` that repeats `#[AsCommand]` with the
 * same name: the framework then removes the module's service of that name.
 */
abstract class AgentConsoleCommand extends Command
{
    /** @var string The option that lifts the AI budget cap for the process */
    public const string OPTION_IGNORE_BUDGET = 'ignore-budget';

    /** @var string The option that names the space of the run by its id */
    public const string OPTION_AGENT_SPACE_ID = 'agentSpaceId';

    /** @var AgentConsoleProgressReporter|null The reporter of the current run; null before a run */
    protected ?AgentConsoleProgressReporter $progressReporter = null;

    /** @var string[] The names of the space options the agent-spaces service declared on this command */
    protected array $agentSpaceScopeOptionNames = [];

    /**
     * Runs {@see executeConsoleWork()} inside what the command asked for: the progress reporter, the budget grant, the
     * default account, the lifted rights and the run's usage envelope. Whatever throws — the set-up, the work or the
     * closing of the envelope — the envelope is closed, then the rights are restored exactly once, then the account
     * authenticated before the run is authenticated again; each step runs even when the one before it threw. A command
     * that opts into none of these leaves the process state as it found it (the account is authenticated again only
     * when the run changed it).
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int the exit code of the work
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->progressReporter = $this->buildProgressReporter($output);
        $this->grantBudgetBypassWhenRequested($input);
        $accountBeforeRun = AuthService::instance()->getAccount();
        $entityRightsRestrictionsLifted = false;
        $usageEnvelopeContextOfRun = null;
        try {
            if ($this->usesDefaultAccountForConsoleWork()) {
                $this->authenticateDefaultAccountForConsoleWork();
            }
            if ($this->liftsEntityRightsRestrictions()) {
                DDDService::instance()->deactivateEntityRightsRestrictions();
                $entityRightsRestrictionsLifted = true;
            }
            $usageEnvelopeScope = $this->getUsageEnvelopeScope($input);
            if ($usageEnvelopeScope !== null) {
                $usageEnvelopeContextOfRun = $this->openUsageEnvelope($this->findUsageEnvelopeAccountId($input), $usageEnvelopeScope);
            }
            return $this->executeConsoleWork($input, $output);
        } finally {
            try {
                if ($usageEnvelopeContextOfRun !== null) {
                    $this->closeUsageEnvelope($usageEnvelopeContextOfRun);
                }
            } finally {
                try {
                    if ($entityRightsRestrictionsLifted) {
                        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
                    }
                } finally {
                    $this->restoreAccountOfBeforeRun($accountBeforeRun);
                }
            }
        }
    }

    /**
     * Authenticates `$accountBeforeRun` again when the run left another account authenticated; leaves the authentication
     * untouched otherwise (an application's authentication service may re-apply settings on every call).
     *
     * @param Account|null $accountBeforeRun the account authenticated when the run started
     * @return void
     */
    protected function restoreAccountOfBeforeRun(?Account $accountBeforeRun): void
    {
        if (AuthService::instance()->getAccount() !== $accountBeforeRun) {
            AuthService::instance()->setAccount($accountBeforeRun);
        }
    }

    /**
     * The work of the command, run inside what {@see execute()} set up.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int the exit code (`Command::SUCCESS`, `Command::FAILURE`, `Command::INVALID`)
     */
    abstract protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int;

    /**
     * Whether the run works as the configured default account for console work. The default: no (the run keeps the
     * account authenticated before it, none in a cron).
     *
     * @return bool
     */
    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return false;
    }

    /**
     * Whether the run reads and writes with the entity rights lifted. The default: no.
     *
     * @return bool
     */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return false;
    }

    /**
     * The `ENVELOPE.` scope the whole run books its model calls under, opened before {@see executeConsoleWork()} for
     * the account {@see findUsageEnvelopeAccountId()} names; null = the run opens no envelope of its own (a command
     * that knows the account only inside its work opens one per step). The default: null.
     *
     * @param InputInterface $input
     * @return string|null
     */
    protected function getUsageEnvelopeScope(InputInterface $input): ?string
    {
        return null;
    }

    /**
     * The account the envelope of the run is billed to (a command may read it from an option). The default: the
     * account authenticated when the envelope opens.
     *
     * @param InputInterface $input
     * @return int|null
     */
    protected function findUsageEnvelopeAccountId(InputInterface $input): ?int
    {
        $authenticatedAccount = AuthService::instance()->getAccount();
        return $authenticatedAccount !== null && isset($authenticatedAccount->id) ? (int)$authenticatedAccount->id : null;
    }

    /**
     * Opens a usage envelope for one step of the work; close it with {@see closeUsageEnvelope()} in a `finally`.
     *
     * @param int|null $accountId the account the envelope's model calls are billed to
     * @param string $usageEnvelopeScope an `ENVELOPE.` scope
     * @param string $initiatedBy who started the work ({@see AIUsageInitiator}); a console run by default
     * @return AIUsageEnvelopeContext the opened envelope
     * @throws InternalErrorException when the scope is no envelope scope or the opening cannot be stored
     */
    protected function openUsageEnvelope(?int $accountId, string $usageEnvelopeScope, string $initiatedBy = AIUsageInitiator::CLI): AIUsageEnvelopeContext
    {
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        return $aIUsageEnvelopesService->openEnvelope($accountId, $usageEnvelopeScope, $initiatedBy);
    }

    /**
     * Closes an envelope opened with {@see openUsageEnvelope()} (and every envelope opened inside it that is still open).
     *
     * @param AIUsageEnvelopeContext $usageEnvelopeContext
     * @return void
     * @throws InternalErrorException
     */
    protected function closeUsageEnvelope(AIUsageEnvelopeContext $usageEnvelopeContext): void
    {
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        $aIUsageEnvelopesService->closeEnvelope($usageEnvelopeContext);
    }

    /**
     * Declares `--ignore-budget`. Call it from `configure()` in a command whose work makes model calls.
     *
     * @return void
     */
    protected function configureIgnoreBudgetOption(): void
    {
        $this->addOption(
            self::OPTION_IGNORE_BUDGET,
            null,
            InputOption::VALUE_NONE,
            'Lift the AI budget cap for this process (the spend is still booked)'
        );
    }

    /**
     * Whether the run asks for the budget bypass: the command declares `--ignore-budget` and it was passed.
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function isBudgetBypassRequested(InputInterface $input): bool
    {
        return $input->hasOption(self::OPTION_IGNORE_BUDGET) && (bool)$input->getOption(self::OPTION_IGNORE_BUDGET);
    }

    /**
     * Grants the budget bypass for the process when {@see isBudgetBypassRequested()} (reason: the console option). The
     * grant is not restored: it ends with the process, and the worker reset of `mgamadeus/ddd-ai` clears it.
     *
     * @param InputInterface $input
     * @return bool whether the bypass was granted
     */
    protected function grantBudgetBypassWhenRequested(InputInterface $input): bool
    {
        if (!$this->isBudgetBypassRequested($input)) {
            return false;
        }
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        $aIUsageEnvelopesService->grantBudgetBypassForTheProcess(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION));
        return true;
    }

    /**
     * Authenticates the configured default account for console work, when one is configured.
     *
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function authenticateDefaultAccountForConsoleWork(): void
    {
        $defaultAccount = $this->findDefaultAccountForConsoleWork();
        if ($defaultAccount !== null) {
            AuthService::instance()->setAccount($defaultAccount);
        }
    }

    /**
     * The configured default account for console work, or null when none is configured.
     *
     * @return Account|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findDefaultAccountForConsoleWork(): ?Account
    {
        return DDDService::instance()->getDefaultAccountForCliOperations();
    }

    /**
     * Authenticates `$account` for the next step (a message dispatched now carries its id). Returns false and leaves
     * the authenticated account as it is when `$account` is null: the caller skips that step, so that it never runs
     * under the account of the step before. The account authenticated before the run is restored when the run ends.
     *
     * @param Account|null $account
     * @return bool whether `$account` is now authenticated
     */
    protected function actAsAccount(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }
        AuthService::instance()->setAccount($account);
        return true;
    }

    /**
     * Declares `--agentSpaceId` and every option the agent-spaces service declares for naming a space in the
     * application's words ({@see AgentSpacesService::getConsoleScopeOptions()}). Call it from `configure()`. An option
     * the command declared itself before is kept as declared and still resolved through the service.
     *
     * @return void
     */
    protected function configureAgentSpaceOptions(): void
    {
        $this->addOption(
            self::OPTION_AGENT_SPACE_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'The id of the space the command works for (omit it for work at account level)'
        );
        $agentSpacesService = $this->getAgentSpacesService();
        foreach ($agentSpacesService->getConsoleScopeOptions()->getElements() as $agentSpaceConsoleScopeOption) {
            if (!$this->getDefinition()->hasOption($agentSpaceConsoleScopeOption->name)) {
                $this->addOption($agentSpaceConsoleScopeOption->name, null, InputOption::VALUE_REQUIRED, $agentSpaceConsoleScopeOption->description);
            }
            $this->agentSpaceScopeOptionNames[] = $agentSpaceConsoleScopeOption->name;
        }
    }

    /**
     * The space the input names: by `--agentSpaceId`, else by the application's options
     * ({@see AgentSpacesService::findAgentSpaceForConsoleScopeOptionValues()}, a value the command declared as the
     * option's default included); null when neither has a value (work at account level).
     *
     * The lookup runs under the rights of the run: the authenticated account (or the lifted rights) decides which
     * spaces it reads, so "no space" can also mean "no space this run may read". Passing `--agentSpaceId` together
     * with one of the application's options is refused; an application option that only carries the default the
     * command declared does not count as passed. `--agentSpaceId` with an empty value is refused (it never means
     * account level).
     *
     * @param InputInterface $input
     * @return AgentSpace|null
     * @throws InvalidOptionException when the space is named twice, the id is empty or no positive whole number, or
     *                                no space matches
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function resolveAgentSpaceFromInput(InputInterface $input): ?AgentSpace
    {
        $agentSpaceIdPassed = $input->hasOption(self::OPTION_AGENT_SPACE_ID) && $input->hasParameterOption('--' . self::OPTION_AGENT_SPACE_ID, true);
        $agentSpaceIdValue = $input->hasOption(self::OPTION_AGENT_SPACE_ID) ? trim((string)$input->getOption(self::OPTION_AGENT_SPACE_ID)) : '';
        $passedScopeOptionNames = $this->findPassedAgentSpaceScopeOptionNames($input);
        if ($agentSpaceIdPassed && $passedScopeOptionNames !== []) {
            throw new InvalidOptionException(
                'Name the space once: --' . self::OPTION_AGENT_SPACE_ID . ' or --' . implode(' / --', $passedScopeOptionNames)
            );
        }
        $agentSpacesService = $this->getAgentSpacesService();
        if ($agentSpaceIdPassed || $agentSpaceIdValue !== '') {
            if (!ctype_digit($agentSpaceIdValue) || (int)$agentSpaceIdValue <= 0) {
                throw new InvalidOptionException('--' . self::OPTION_AGENT_SPACE_ID . " must be a positive whole number, got '{$agentSpaceIdValue}'");
            }
            $agentSpace = $agentSpacesService->find((int)$agentSpaceIdValue);
            if (!$agentSpace instanceof AgentSpace) {
                throw new InvalidOptionException("No space with the id {$agentSpaceIdValue} that this run may read");
            }
            return $agentSpace;
        }
        $scopeOptionValuesByName = $this->findGivenAgentSpaceScopeOptionValues($input);
        if ($scopeOptionValuesByName === []) {
            return null;
        }
        $agentSpace = $agentSpacesService->findAgentSpaceForConsoleScopeOptionValues($scopeOptionValuesByName);
        if (!$agentSpace instanceof AgentSpace) {
            throw new InvalidOptionException('No space that this run may read for ' . $this->describeOptionValues($scopeOptionValuesByName));
        }
        return $agentSpace;
    }

    /**
     * The space options of the agent-spaces service that were passed on the command line (an option that only carries
     * the default the command declared is not passed).
     *
     * @param InputInterface $input
     * @return string[]
     */
    protected function findPassedAgentSpaceScopeOptionNames(InputInterface $input): array
    {
        $passedScopeOptionNames = [];
        foreach ($this->agentSpaceScopeOptionNames as $scopeOptionName) {
            if ($input->hasOption($scopeOptionName) && $input->hasParameterOption('--' . $scopeOptionName, true)) {
                $passedScopeOptionNames[] = $scopeOptionName;
            }
        }
        return $passedScopeOptionNames;
    }

    /**
     * The account that owns `$agentSpace` ({@see AgentSpacesService::findOwnerAccountOfAgentSpace()}).
     *
     * @param AgentSpace $agentSpace
     * @return Account|null null = no owner known
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findOwnerAccountOfAgentSpace(AgentSpace $agentSpace): ?Account
    {
        $agentSpacesService = $this->getAgentSpacesService();
        return $agentSpacesService->findOwnerAccountOfAgentSpace($agentSpace);
    }

    /**
     * The non-empty values of the space options the agent-spaces service declared, by option name (a default the
     * command declared included).
     *
     * @param InputInterface $input
     * @return array<string, string>
     */
    protected function findGivenAgentSpaceScopeOptionValues(InputInterface $input): array
    {
        $scopeOptionValuesByName = [];
        foreach ($this->agentSpaceScopeOptionNames as $scopeOptionName) {
            $scopeOptionValue = $input->hasOption($scopeOptionName) ? trim((string)$input->getOption($scopeOptionName)) : '';
            if ($scopeOptionValue !== '') {
                $scopeOptionValuesByName[$scopeOptionName] = $scopeOptionValue;
            }
        }
        return $scopeOptionValuesByName;
    }

    /**
     * @param array<string, string> $optionValuesByName
     * @return string `--name=value` pairs, space-separated
     */
    protected function describeOptionValues(array $optionValuesByName): string
    {
        $optionValueTexts = [];
        foreach ($optionValuesByName as $optionName => $optionValue) {
            $optionValueTexts[] = "--{$optionName}={$optionValue}";
        }
        return implode(' ', $optionValueTexts);
    }

    /**
     * The progress reporter of the current run (one that drops every line before a run).
     *
     * @return AgentConsoleProgressReporter
     */
    protected function getProgressReporter(): AgentConsoleProgressReporter
    {
        return $this->progressReporter ?? AgentConsoleProgressReporter::withoutOutput();
    }

    /**
     * Builds the progress reporter of a run. Override it for a prefix or a style of the lines.
     *
     * @param OutputInterface $output
     * @return AgentConsoleProgressReporter
     */
    protected function buildProgressReporter(OutputInterface $output): AgentConsoleProgressReporter
    {
        return AgentConsoleProgressReporter::forOutput($output);
    }

    /**
     * The usage envelope service of `mgamadeus/ddd-ai` (the application's class, when it replaces it).
     *
     * @return AIUsageEnvelopesService
     */
    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        return $aIUsageEnvelopesService;
    }

    /**
     * The agent-spaces service (the application's class, when it replaces it).
     *
     * @return AgentSpacesService
     */
    protected function getAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService;
    }
}
