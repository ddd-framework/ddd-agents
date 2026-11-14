<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The mark half of the idle finalize, run by a cron every five minutes: sets `finalizedAt` on every root conversation
 * idle past the window (no live run, no turn-owing inbound message newer than the window) in ONE indexed UPDATE
 * ({@see AIConversationsService::markIdleConversationsFinalized()}: no SELECT, no hydration). The completion compaction
 * of the marked conversations is a separate sweep
 * ({@see AIConversationsService::findFinalizedConversationIdsOwingCompletionCompaction()}). A new inbound message
 * clears `finalizedAt` again ({@see AIConversationsService::recordInboundMessageActivity()}), so a resumed conversation
 * re-enters this sweep after its next idle window.
 *
 *   php bin/console app:ai-conversation:mark-finalized
 *   php bin/console app:ai-conversation:mark-finalized --idle-minutes=30 --dry-run
 */
#[AsCommand(
    name: 'app:ai-conversation:mark-finalized',
    description: 'Idle-finalize mark sweep: set finalizedAt on conversations idle past the window (one indexed UPDATE).',
)]
class AgentMarkFinalizedConversationsCommand extends AgentConsoleCommand
{
    /** @var int Minutes without a turn-owing inbound message before a conversation is finalized */
    public const int DEFAULT_IDLE_MINUTES = 60;

    /** @var string The option of the idle window in minutes */
    public const string OPTION_IDLE_MINUTES = 'idle-minutes';

    /** @var string The option that counts instead of writing */
    public const string OPTION_DRY_RUN = 'dry-run';

    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_IDLE_MINUTES,
            null,
            InputOption::VALUE_OPTIONAL,
            'Minutes without a new turn-owing inbound message before a conversation is finalized',
            (string)self::DEFAULT_IDLE_MINUTES
        );
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Count the rows the sweep would mark (same predicate), write nothing');
    }

    /**
     * Marks the idle conversations, or counts them with `--dry-run`.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     * @throws InvalidOptionException when `--idle-minutes` is no positive whole number
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $idleMinutes = $this->readIdleMinutes($input);
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();

        if ((bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $wouldMarkCount = $aIConversationsService->countIdleConversationsToFinalize($idleMinutes);
            $output->writeln("dry-run: {$wouldMarkCount} conversation(s) would be finalized (idle ≥ {$idleMinutes} min)");
            return Command::SUCCESS;
        }

        $markedCount = $aIConversationsService->markIdleConversationsFinalized($idleMinutes);
        $output->writeln("finalized {$markedCount} conversation(s) (idle ≥ {$idleMinutes} min)");
        return Command::SUCCESS;
    }

    /**
     * The idle window in minutes: the option's value, or the default when it is not passed or blank.
     *
     * @param InputInterface $input
     * @return int
     * @throws InvalidOptionException when the value is no positive whole number
     */
    protected function readIdleMinutes(InputInterface $input): int
    {
        $idleMinutesValue = trim((string)$input->getOption(self::OPTION_IDLE_MINUTES));
        if ($idleMinutesValue === '') {
            return self::DEFAULT_IDLE_MINUTES;
        }
        if (!ctype_digit($idleMinutesValue) || (int)$idleMinutesValue <= 0) {
            throw new InvalidOptionException('--' . self::OPTION_IDLE_MINUTES . " must be a positive whole number of minutes, got '{$idleMinutesValue}'");
        }
        return (int)$idleMinutesValue;
    }
}
