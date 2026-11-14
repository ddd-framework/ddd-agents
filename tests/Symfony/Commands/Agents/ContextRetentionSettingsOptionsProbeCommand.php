<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettingsOverride;
use DDD\Symfony\Commands\Agents\ContextRetentionSettingsOptionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * A command that declares the options of {@see ContextRetentionSettingsOptionsTrait} (the compaction options with the
 * prefix it is built with) and hands the parsed value objects to a test.
 */
class ContextRetentionSettingsOptionsProbeCommand extends Command
{
    use ContextRetentionSettingsOptionsTrait;

    /**
     * @param string $compactionOptionNamePrefix
     * @param string[] $compactionDefaultValuesByField
     */
    public function __construct(
        protected string $compactionOptionNamePrefix = self::COMPACTION_OPTION_PREFIX,
        protected array $compactionDefaultValuesByField = []
    ) {
        parent::__construct('agents-test:context-retention-options');
    }

    protected function configure(): void
    {
        $this->addContextRetentionSettingsOptions();
        $this->addCompactionSettingsOptions($this->compactionOptionNamePrefix, $this->compactionDefaultValuesByField);
    }

    /** @param array<string, string|bool> $commandInput */
    public function buildContextRetentionOverride(array $commandInput): ?ContextRetentionSettingsOverride
    {
        return $this->buildContextRetentionSettingsOverrideFromInput(new ArrayInput($commandInput, $this->getDefinition()));
    }

    /** @param array<string, string|bool> $commandInput */
    public function buildCompactionOverride(array $commandInput): ?CompactionSettingsOverride
    {
        return $this->buildCompactionSettingsOverrideFromInput(new ArrayInput($commandInput, $this->getDefinition()), $this->compactionOptionNamePrefix);
    }
}
