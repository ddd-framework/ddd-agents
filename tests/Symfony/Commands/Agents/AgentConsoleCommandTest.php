<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * The shared base of the module's commands: what a run sets up is taken down again whatever throws (set-up, work, the
 * closing of the envelope), a command that opts into nothing leaves the process state alone, `--ignore-budget` grants
 * the process bypass, and the space options of the application's agent-spaces service are declared and resolved.
 */
class AgentConsoleCommandTest extends UnitTestCase
{
    public const int ACCOUNT_ID_BEFORE_RUN = 3;

    public const int DEFAULT_ACCOUNT_ID = 9;

    protected ?Account $accountBeforeTest = null;

    protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble;

    protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    protected CountingAuthServiceDouble $countingAuthServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        $this->countingAuthServiceDouble = new CountingAuthServiceDouble();
        self::setSingletonInstance(AuthService::class, $this->countingAuthServiceDouble);
        $this->agentSpacesServiceDouble = new AgentSpacesServiceConsoleScopeDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testEverythingSetUpIsTakenDownWhenTheWorkThrows(): void
    {
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $probeCommand = $this->buildProbeCommand($this->buildAccountWithId(self::DEFAULT_ACCOUNT_ID));
        $probeCommand->usesDefaultAccount = true;
        $probeCommand->liftsRights = true;
        $probeCommand->usageEnvelopeScope = AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION;
        $probeCommand->throwsInWork = true;

        $this->assertRightsRestoredExactlyOnceAround($probeCommand, AgentConsoleProbeCommand::FAILURE_MESSAGE);

        self::assertFalse($probeCommand->rightsAppliedDuringWork);
        self::assertSame(self::DEFAULT_ACCOUNT_ID, (int)$probeCommand->accountDuringWork?->id);
        self::assertTrue($probeCommand->envelopeOpenDuringWork);
        self::assertFalse($this->usageEnvelopesServiceDouble->hasActiveEnvelope());
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
    }

    public function testRightsAndAccountAreRestoredWhenClosingTheEnvelopeThrows(): void
    {
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $probeCommand = $this->buildProbeCommand($this->buildAccountWithId(self::DEFAULT_ACCOUNT_ID));
        $probeCommand->usesDefaultAccount = true;
        $probeCommand->liftsRights = true;
        $probeCommand->usageEnvelopeScope = AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION;
        $this->usageEnvelopesServiceDouble->closingThrows = true;

        $this->assertRightsRestoredExactlyOnceAround($probeCommand, RecordingUsageEnvelopesServiceDouble::CLOSING_FAILURE_MESSAGE);

        self::assertTrue($probeCommand->workRan);
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
    }

    public function testRightsAndAccountAreRestoredWhenTheSetUpFails(): void
    {
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $probeCommand = $this->buildProbeCommand($this->buildAccountWithId(self::DEFAULT_ACCOUNT_ID));
        $probeCommand->usesDefaultAccount = true;
        $probeCommand->liftsRights = true;
        $probeCommand->usageEnvelopeScope = AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION;
        $this->usageEnvelopesServiceDouble->openingFails = true;

        $this->assertRightsRestoredExactlyOnceAround($probeCommand, RecordingUsageEnvelopesServiceDouble::OPENING_FAILURE_MESSAGE);

        self::assertFalse($probeCommand->workRan);
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
    }

    public function testACommandThatOptsIntoNothingNeverSetsTheAccount(): void
    {
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $setAccountCallsBeforeRun = $this->countingAuthServiceDouble->setAccountCallCount;
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->throwsInWork = true;

        $this->runExpectingFailure($probeCommand, [], AgentConsoleProbeCommand::FAILURE_MESSAGE);
        (new CommandTester($this->buildProbeCommand()))->execute([]);

        self::assertSame($setAccountCallsBeforeRun, $this->countingAuthServiceDouble->setAccountCallCount);
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
        self::assertSame(DBEntity::getApplyRightsRestrictions(), $probeCommand->rightsAppliedDuringWork);
        self::assertSame([], $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
    }

    public function testTheEnvelopeOfTheRunIsClosedWhenTheWorkThrows(): void
    {
        $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->usageEnvelopeScope = AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION;
        $probeCommand->throwsInWork = true;

        $this->runExpectingFailure($probeCommand, [], AgentConsoleProbeCommand::FAILURE_MESSAGE);

        self::assertTrue($probeCommand->envelopeOpenDuringWork);
        self::assertFalse($this->usageEnvelopesServiceDouble->hasActiveEnvelope());
        self::assertCount(1, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
        $openedEnvelopeContext = $this->usageEnvelopesServiceDouble->openedEnvelopeContexts[0];
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $openedEnvelopeContext->scope);
        self::assertSame(AIUsageInitiator::CLI, $openedEnvelopeContext->initiatedBy);
        self::assertSame(self::ACCOUNT_ID_BEFORE_RUN, $openedEnvelopeContext->accountId);
        self::assertSame([$openedEnvelopeContext], $this->usageEnvelopesServiceDouble->closedEnvelopeContexts);
    }

    public function testAStepEnvelopeNamesItsInitiator(): void
    {
        $probeCommand = $this->buildProbeCommand();

        /** @var AIUsageEnvelopeContext $usageEnvelopeContext */
        $usageEnvelopeContext = self::invokeMethod($probeCommand, 'openUsageEnvelope', [5, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION, AIUsageInitiator::ACCOUNT]);
        self::invokeMethod($probeCommand, 'closeUsageEnvelope', [$usageEnvelopeContext]);

        self::assertSame(AIUsageInitiator::ACCOUNT, $usageEnvelopeContext->initiatedBy);
        self::assertSame(5, $usageEnvelopeContext->accountId);
        self::assertFalse($this->usageEnvelopesServiceDouble->hasActiveEnvelope());
    }

    public function testActingAsNoAccountKeepsTheAuthenticatedAccount(): void
    {
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);
        $probeCommand = $this->buildProbeCommand();

        self::assertFalse(self::invokeMethod($probeCommand, 'actAsAccount', [null]));
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
        self::assertTrue(self::invokeMethod($probeCommand, 'actAsAccount', [$this->buildAccountWithId(self::DEFAULT_ACCOUNT_ID)]));
        self::assertSame(self::DEFAULT_ACCOUNT_ID, (int)AuthService::instance()->getAccount()?->id);
    }

    public function testIgnoreBudgetGrantsTheProcessBypassForTheConsoleOption(): void
    {
        (new CommandTester($this->buildProbeCommand()))->execute([]);
        self::assertNull($this->usageEnvelopesServiceDouble->getBudgetBypassGrantForTheProcess());

        (new CommandTester($this->buildProbeCommand()))->execute(['--' . AgentConsoleCommand::OPTION_IGNORE_BUDGET => true]);
        self::assertSame(
            AIBudgetBypassGrant::REASON_CONSOLE_OPTION,
            $this->usageEnvelopesServiceDouble->getBudgetBypassGrantForTheProcess()?->reason
        );
    }

    public function testTheSpaceOptionsOfTheAgentSpacesServiceAreDeclared(): void
    {
        $commandDefinition = $this->buildProbeCommand()->getDefinition();

        self::assertTrue($commandDefinition->hasOption(AgentConsoleCommand::OPTION_AGENT_SPACE_ID));
        self::assertTrue($commandDefinition->hasOption(AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME));
        self::assertTrue($commandDefinition->getOption(AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME)->isValueRequired());
    }

    public function testNoSpaceOptionMeansWorkAtAccountLevel(): void
    {
        $probeCommand = $this->buildProbeCommand();
        (new CommandTester($probeCommand))->execute([]);

        self::assertNull($probeCommand->resolvedAgentSpace);
        self::assertSame([], $this->agentSpacesServiceDouble->askedScopeOptionValues);
    }

    public function testTheSpaceIsResolvedByItsIdWithItsOwner(): void
    {
        $probeCommand = $this->buildProbeCommand();
        (new CommandTester($probeCommand))->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]);

        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, (int)$probeCommand->resolvedAgentSpace?->id);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID, (int)$probeCommand->ownerAccountOfResolvedAgentSpace?->id);
    }

    public function testTheSpaceIsResolvedByTheApplicationsOption(): void
    {
        $probeCommand = $this->buildProbeCommand();
        (new CommandTester($probeCommand))->execute(['--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => ' ' . AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE . ' ']);

        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, (int)$probeCommand->resolvedAgentSpace?->id);
        self::assertSame(
            [[AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE]],
            $this->agentSpacesServiceDouble->askedScopeOptionValues
        );
    }

    public function testADefaultTheCommandDeclaredResolvesTheSpaceAndDoesNotCountAsNamedTwice(): void
    {
        $probeCommandWithDefault = $this->buildProbeCommand(null, AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE);
        (new CommandTester($probeCommandWithDefault))->execute([]);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, (int)$probeCommandWithDefault->resolvedAgentSpace?->id);

        $probeCommandWithDefault = $this->buildProbeCommand(null, AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE);
        (new CommandTester($probeCommandWithDefault))->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, (int)$probeCommandWithDefault->resolvedAgentSpace?->id);
    }

    public function testAnUnknownValueOfTheApplicationsOptionIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('No space that this run may read for --' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME . '=south');
        (new CommandTester($this->buildProbeCommand()))->execute(['--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => 'south']);
    }

    public function testAnUnknownSpaceIdIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('No space with the id 8 that this run may read');
        (new CommandTester($this->buildProbeCommand()))->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => '8']);
    }

    public function testASpaceIdThatIsNoWholeNumberIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('must be a positive whole number');
        (new CommandTester($this->buildProbeCommand()))->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => 'seven']);
    }

    public function testAnEmptySpaceIdIsRefusedInsteadOfMeaningAccountLevel(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage("--agentSpaceId must be a positive whole number, got ''");
        (new CommandTester($this->buildProbeCommand()))->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => '']);
    }

    public function testASpaceNamedTwiceIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Name the space once');
        (new CommandTester($this->buildProbeCommand()))->execute([
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE,
        ]);
    }

    public function testTheProgressReporterPrintsOnTheOutputOfTheRun(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute([]);

        self::assertStringContainsString(AgentConsoleProbeCommand::PROGRESS_LINE, $commandTester->getDisplay());
    }

    protected function buildProbeCommand(?Account $defaultAccountOfConsoleWork = null, ?string $predeclaredScopeOptionDefault = null): AgentConsoleProbeCommand
    {
        return new AgentConsoleProbeCommand($this->agentSpacesServiceDouble, $this->usageEnvelopesServiceDouble, $defaultAccountOfConsoleWork, $predeclaredScopeOptionDefault);
    }

    /**
     * Runs the command inside an outer lift of the rights, expecting it to fail with `$expectedMessage`: restoring
     * twice would pop the outer lift, restoring never would leave the rights lifted after the outer restore.
     */
    protected function assertRightsRestoredExactlyOnceAround(AgentConsoleProbeCommand $probeCommand, string $expectedMessage): void
    {
        $rightsAppliedBeforeTest = DBEntity::getApplyRightsRestrictions();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->runExpectingFailure($probeCommand, [], $expectedMessage);
            self::assertFalse(DBEntity::getApplyRightsRestrictions(), 'the outer lift is still in place');
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertSame($rightsAppliedBeforeTest, DBEntity::getApplyRightsRestrictions());
    }

    /** @param array<string, string|bool> $commandInput */
    protected function runExpectingFailure(AgentConsoleProbeCommand $probeCommand, array $commandInput, string $expectedMessage): void
    {
        try {
            (new CommandTester($probeCommand))->execute($commandInput);
            self::fail('the run was expected to throw');
        } catch (RuntimeException | InternalErrorException $throwable) {
            self::assertStringContainsString($expectedMessage, $this->describeThrowableChain($throwable));
        }
    }

    protected function describeThrowableChain(Throwable $throwable): string
    {
        $messages = [];
        for ($currentThrowable = $throwable; $currentThrowable !== null; $currentThrowable = $currentThrowable->getPrevious()) {
            $messages[] = $currentThrowable->getMessage();
        }
        return implode(' <- ', $messages);
    }

    protected function authenticateAccountWithId(int $accountId): Account
    {
        $account = $this->buildAccountWithId($accountId);
        AuthService::instance()->setAccount($account);
        return $account;
    }

    protected function buildAccountWithId(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }
}
