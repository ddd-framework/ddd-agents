<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Symfony\Commands\Agents\AgentEvalArmOptionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * A command that declares the arm options of {@see AgentEvalArmOptionsTrait} and hands the resolved arms and the
 * arms object to a test.
 */
class AgentEvalArmOptionsProbeCommand extends Command
{
    use AgentEvalArmOptionsTrait;

    public function __construct()
    {
        parent::__construct('agents-test:eval-arm-options');
    }

    protected function configure(): void
    {
        $this->addEvalArmOptions();
    }

    /**
     * @param array<string, string|bool> $commandInput
     * @return array<string, string>
     */
    public function resolveArms(array $commandInput): array
    {
        return $this->resolveEvalArmOptions(new ArrayInput($commandInput, $this->getDefinition()));
    }

    /** @param array<string, string> $arms */
    public function buildArms(array $arms): AgentEvalArms
    {
        return $this->buildEvalArms($arms);
    }
}
