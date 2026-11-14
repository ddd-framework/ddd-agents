<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\MessageHandlers\AIConversationFinalizeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The work half of the idle finalize, run by a cron every five minutes: for every finalized conversation that still
 * owes its completion compaction (`finalizeCompactedAt` empty or older than `finalizedAt`) it dispatches an ids-only
 * {@see AIConversationFinalizeMessage} to the compaction transport. One indexed SELECT of bare ids
 * ({@see AIConversationsService::findFinalizedConversationIdsOwingCompletionCompaction()}); every load and model call
 * runs in the workers. Idempotent: a worker's completion pass adds nothing to a tree that is already covered and only
 * stamps it again, and an id selected again while its job is queued is dispatched twice without harm. Each dispatch
 * runs as the conversation's own account, so the worker restores the right account.
 *
 * The mark half is {@see AgentMarkFinalizedConversationsCommand}.
 *
 *   php bin/console app:ai-conversation:compact-finalized
 *   php bin/console app:ai-conversation:compact-finalized --batch=100 --dry-run
 *   php bin/console app:ai-conversation:compact-finalized --inline=123 --ignore-budget
 */
#[AsCommand(
    name: 'app:ai-conversation:compact-finalized',
    description: 'Idle-finalize work sweep: dispatch completion compactions for finalized conversations (one indexed SELECT + ids-only dispatches).',
)]
class AgentCompactFinalizedConversationsCommand extends AgentConsoleCommand
{
    /** @var int Conversations dispatched per pass by default */
    public const int DEFAULT_BATCH_SIZE = 50;

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Max conversations dispatched per sweep pass', (string)self::DEFAULT_BATCH_SIZE);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the ids the sweep would dispatch, dispatch nothing');
        $this->addOption('inline', null, InputOption::VALUE_OPTIONAL, 'Run the finalize chain SYNCHRONOUSLY for ONE conversation id (errors reach the console instead of the worker\'s log)');
        $this->addOption('rebuild-summary', null, InputOption::VALUE_NONE, 'With --inline: rebuild the display summary from its full input, ignoring staleness and the previous summary');
        $this->configureIgnoreBudgetOption();
    }

    /** The account lookups read every account; the dispatches carry the conversation's account. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Runs the finalize chain of one conversation inline, or dispatches the owed jobs.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $inlineConversationId = $input->getOption('inline');
        if ($inlineConversationId !== null) {
            return $this->finalizeConversationInline((int)$inlineConversationId, (bool)$input->getOption('rebuild-summary'), $output);
        }
        $batchSize = (int)$input->getOption('batch') ?: self::DEFAULT_BATCH_SIZE;
        $aIConversationsService = $this->getAIConversationsService();
        $owingConversationIds = $aIConversationsService->findFinalizedConversationIdsOwingCompletionCompaction($batchSize);
        if ($owingConversationIds === []) {
            $output->writeln('nothing owed — every finalized conversation is completion-compacted');
            return Command::SUCCESS;
        }
        if ((bool)$input->getOption('dry-run')) {
            $output->writeln('dry-run: would dispatch ' . count($owingConversationIds) . ' finalize job(s): ' . implode(', ', $owingConversationIds));
            return Command::SUCCESS;
        }

        // a conversation without a known account is dispatched under the account of the sweep, never under the
        // account of the conversation dispatched before it
        $accountOfSweep = AuthService::instance()->getAccount();
        $dispatchedCount = 0;
        foreach ($owingConversationIds as $owingConversationId) {
            try {
                $owingConversation = $aIConversationsService->find($owingConversationId, useEntityRegistrCache: false);
                if ($owingConversation instanceof AIConversation) {
                    $this->actAsAccount($owingConversation->account ?? null);
                }
                (new AIConversationFinalizeMessage($owingConversationId))->dispatch();
                $dispatchedCount++;
            } catch (Throwable $dispatchError) {
                $output->writeln("<error>dispatch failed for conversation {$owingConversationId}: {$dispatchError->getMessage()}</error>");
            } finally {
                $this->restoreAccountOfBeforeRun($accountOfSweep);
            }
        }
        $output->writeln("dispatched {$dispatchedCount} finalize job(s) of " . count($owingConversationIds) . ' owed');
        return Command::SUCCESS;
    }

    /**
     * The whole finalize chain of one conversation inline — completion compaction, final summary, stamp — with every
     * error thrown to the console (the worker logs them instead). The worker gets its envelope from the message; here
     * the command opens the compaction envelope itself.
     *
     * @param int $conversationId
     * @param bool $rebuildSummary whether the display summary is rebuilt from its full input
     * @param OutputInterface $output
     * @return int
     */
    protected function finalizeConversationInline(int $conversationId, bool $rebuildSummary, OutputInterface $output): int
    {
        $aIConversationsService = $this->getAIConversationsService();
        $conversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
        if (!$conversation instanceof AIConversation) {
            $output->writeln("<error>No conversation found with id {$conversationId}.</error>");
            return Command::FAILURE;
        }
        $this->actAsAccount($conversation->account ?? null);
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        $usageEnvelopeContext = $this->openUsageEnvelope(
            $conversation->accountId ?? null,
            AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION,
            AIUsageInitiator::ACCOUNT
        );
        try {
            $aIConversationCompactionService->finalizeConversationCompaction($conversation);
            if ($rebuildSummary) {
                // a summary written before an input improvement is carried forward unchanged by an incremental refresh
                $aIConversationsService->generateAndPersistSummary($conversation, async: false, forceFullRebuild: true);
            } elseif (!isset($conversation->summary)) {
                // the finalize chain refreshes the summary on a best-effort basis: run it again without the catch so
                // the real error reaches the console
                $aIConversationsService->generateAndPersistSummary($conversation, async: false);
            }
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
        $summaryLine = isset($conversation->summary)
            ? (($conversation->summary->title ?? '—') . ' | outcome: ' . ($conversation->summary->outcome ?? '—')
                . ' | importance: ' . ($conversation->summary->importance ?? '—')
                . ' | coveredUpTo: ' . ($conversation->summary->coveredUpToMessageIndex ?? '—'))
            : 'NO SUMMARY';
        $output->writeln("finalized conversation {$conversationId} inline — summary: {$summaryLine}");
        return Command::SUCCESS;
    }

    /** @return AIConversationsService */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        return $aIConversationsService;
    }
}
