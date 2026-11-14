<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Embeds the conversation messages that still have no recall embedding: the catch-up for an existing corpus and for
 * missed asynchronous embeddings (a new message is embedded off the turn by
 * {@see \DDD\Domain\AI\MessageHandlers\AIConversationMessageEmbeddingMessage}). An embedding is a row of its own
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding}), so "not embedded"
 * is a message without such a row ({@see DBAIConversationMessages::findUnembedded()}); each message is embedded with
 * {@see AIConversationMessagesService::embedMessage()}.
 *
 * Idempotent and resumable: the pages advance by message id, a re-run only embeds what is still missing. The run reads
 * every account's messages: the rights are lifted for the run.
 *
 *   php bin/console app:ai-conversation:backfill-message-embeddings
 *   php bin/console app:ai-conversation:backfill-message-embeddings --async --batch=500
 */
#[AsCommand(
    name: 'app:ai-conversation:backfill-message-embeddings',
    description: 'Embed conversation messages still missing their recall vector.'
)]
class AIConversationBackfillEmbeddingsCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Messages per page', '100')
            ->addOption('max', null, InputOption::VALUE_OPTIONAL, 'Stop after this many messages (0 = all)', '0')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch each message without an embedding to the embedding worker instead of embedding it inline (needs running embedding workers); the command only enqueues and returns.');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Pages over the messages without an embedding and embeds (or dispatches) each.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $batchSize = max(1, (int)$input->getOption('batch'));
        $maximumMessages = max(0, (int)$input->getOption('max'));
        $async = (bool)$input->getOption('async');

        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $messagesRepository = $this->getMessagesRepository();

        $processedCount = 0;
        $withoutVectorCount = 0;
        $failedCount = 0;
        // Both modes page by id: a dispatched message has no embedding row until the worker runs, and a message whose
        // embedding call yields no vector gets no row at all; re-querying the rows without one would loop forever.
        $afterMessageId = 0;
        while (true) {
            $unembeddedMessages = $messagesRepository->findUnembedded($batchSize, $afterMessageId)?->getElements() ?? [];
            if ($unembeddedMessages === []) {
                break;
            }
            foreach ($unembeddedMessages as $unembeddedMessage) {
                try {
                    if ($aIConversationMessagesService->embedMessage($unembeddedMessage, async: $async)) {
                        $processedCount++;
                    } else {
                        $withoutVectorCount++;
                    }
                } catch (Throwable $embeddingError) {
                    $failedCount++;
                    $symfonyStyle->warning("message {$unembeddedMessage->id}: {$embeddingError->getMessage()}");
                }
                $afterMessageId = (int)$unembeddedMessage->id;
                if ($maximumMessages > 0 && $processedCount >= $maximumMessages) {
                    break 2;
                }
            }
            $symfonyStyle->text(($async ? 'dispatched ' : 'embedded ') . "$processedCount so far, $withoutVectorCount without a vector…");
        }

        $verb = $async ? 'dispatched' : 'embedded';
        $symfonyStyle->success("Backfill done — $verb $processedCount message(s)"
            . ($withoutVectorCount > 0 ? ", $withoutVectorCount without a vector (no row written, logged)" : '')
            . ($failedCount > 0 ? ", $failedCount failed" : '')
            . ($async ? ' — the embedding workers process the queue.' : '.'));
        return Command::SUCCESS;
    }

    /** @return AIConversationMessagesService */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** @return DBAIConversationMessages the repository of the message set (the application's class, when it replaces it) */
    protected function getMessagesRepository(): DBAIConversationMessages
    {
        /** @var DBAIConversationMessages $messagesRepository */
        $messagesRepository = AIConversationMessages::getRepoClassInstance();
        return $messagesRepository;
    }
}
