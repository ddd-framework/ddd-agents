<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A concrete command on {@see AgentConsoleCommand} whose switches a test sets and which records what its work saw:
 * the rights, the authenticated account, the open envelope, the resolved space and its owner. Its work throws on
 * demand, after recording.
 */
class AgentConsoleProbeCommand extends AgentConsoleCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:probe';

    public const string FAILURE_MESSAGE = 'the work failed';

    public const string PROGRESS_LINE = 'step <one> done';

    public bool $usesDefaultAccount = false;

    public bool $liftsRights = false;

    public ?string $usageEnvelopeScope = null;

    public bool $throwsInWork = false;

    public ?bool $rightsAppliedDuringWork = null;

    public ?Account $accountDuringWork = null;

    public bool $envelopeOpenDuringWork = false;

    public ?AgentSpace $resolvedAgentSpace = null;

    public ?Account $ownerAccountOfResolvedAgentSpace = null;

    public bool $workRan = false;

    /**
     * @param AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble
     * @param RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble
     * @param Account|null $defaultAccountOfConsoleWork
     * @param string|null $predeclaredScopeOptionDefault when set, the command declares the application's space option
     *                                                   itself, with this default, before the base adds the space options
     */
    public function __construct(
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        protected ?Account $defaultAccountOfConsoleWork = null,
        protected ?string $predeclaredScopeOptionDefault = null
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function configure(): void
    {
        $this->configureIgnoreBudgetOption();
        if ($this->predeclaredScopeOptionDefault !== null) {
            $this->addOption(
                AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'The key of the space, declared by the command with a default',
                $this->predeclaredScopeOptionDefault
            );
        }
        $this->configureAgentSpaceOptions();
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $this->workRan = true;
        $this->rightsAppliedDuringWork = DBEntity::getApplyRightsRestrictions();
        $this->accountDuringWork = AuthService::instance()->getAccount();
        $this->envelopeOpenDuringWork = $this->usageEnvelopesServiceDouble->hasActiveEnvelope();
        $this->getProgressReporter()->reportProgress(self::PROGRESS_LINE);
        $this->resolvedAgentSpace = $this->resolveAgentSpaceFromInput($input);
        if ($this->resolvedAgentSpace !== null) {
            $this->ownerAccountOfResolvedAgentSpace = $this->findOwnerAccountOfAgentSpace($this->resolvedAgentSpace);
        }
        if ($this->throwsInWork) {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }
        return Command::SUCCESS;
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return $this->usesDefaultAccount;
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return $this->liftsRights;
    }

    protected function getUsageEnvelopeScope(InputInterface $input): ?string
    {
        return $this->usageEnvelopeScope;
    }

    protected function findDefaultAccountForConsoleWork(): ?Account
    {
        return $this->defaultAccountOfConsoleWork;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }
}
