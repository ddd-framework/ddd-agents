<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Prints the progress lines a long-running service reports ({@see AIConversationProgressReporterInterface}) on the
 * output of the console command that started it: one line per report, with an optional prefix and an optional console
 * style tag around it. A service takes this object instead of a callback. The reported text is printed as text: a
 * `<…>` in it is never read as a style tag.
 *
 * A plain object of one command run, built per run with {@see forOutput()}; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentConsoleProgressReporter implements AIConversationProgressReporterInterface
{
    /** @var OutputInterface|null Where the lines go; null = the lines are dropped */
    protected ?OutputInterface $output = null;

    /** @var string Printed in front of every line (may carry console style tags) */
    protected string $linePrefix = '';

    /** @var string|null A console style tag (e.g. `comment`) around every line; null = none */
    protected ?string $styleTag = null;

    /**
     * @param OutputInterface $output where the lines go
     * @param string $linePrefix printed in front of every line
     * @param string|null $styleTag a console style tag around every line, null for none
     * @return static
     */
    public static function forOutput(OutputInterface $output, string $linePrefix = '', ?string $styleTag = null): static
    {
        $agentConsoleProgressReporter = new static();
        $agentConsoleProgressReporter->output = $output;
        $agentConsoleProgressReporter->linePrefix = $linePrefix;
        $agentConsoleProgressReporter->styleTag = $styleTag;
        return $agentConsoleProgressReporter;
    }

    /**
     * A reporter that drops every line (before a command run has an output).
     *
     * @return static
     */
    public static function withoutOutput(): static
    {
        return new static();
    }

    /**
     * Prints one progress line.
     *
     * @param string $progressLine
     * @return void
     */
    public function reportProgress(string $progressLine): void
    {
        if ($this->output === null) {
            return;
        }
        $printedLine = $this->linePrefix . OutputFormatter::escape($progressLine);
        $this->output->writeln($this->styleTag !== null ? "<{$this->styleTag}>{$printedLine}</{$this->styleTag}>" : $printedLine);
    }
}
