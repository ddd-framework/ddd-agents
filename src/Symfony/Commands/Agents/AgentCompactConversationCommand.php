<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionConsistencyReport;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs and evaluates the hierarchical compaction tree of one persisted conversation from the console. Synchronous: the
 * compaction round runs inline (no worker), optionally with compaction knobs for this run, then the resulting tree and
 * the cover the model would receive are printed.
 *
 * Prints:
 *   1. the TREE — every node by level (coarse → fine), its covered message-index range, covered-message count, source
 *      (raw leaf or roll-up), model, and a summary preview;
 *   2. the COVER — the smallest recency-graded node set the model context would receive (oldest → newest), its edge
 *      (coveredUpTo) and the size of the raw tail after it. `--as-of-message=N` reconstructs the cover as it was when
 *      message N was produced (the tree is never rewritten, so a past cover can be rebuilt).
 *
 *   php bin/console app:ai-agent:compact 48                                    # run a round + show the tree/cover
 *   php bin/console app:ai-agent:compact 48 --segment-messages=2 --fan-in=2 --keep-messages=2 --ignore-budget
 *   php bin/console app:ai-agent:compact 48 --show-only                        # just render (no new compaction)
 *   php bin/console app:ai-agent:compact 48 --show-only --as-of-message=30     # the cover as of message 30
 *
 * The compaction knobs are merged onto the conversation's stored settings for this run (a knob not passed keeps its
 * stored value); a value below a knob's minimum is refused. The run reads any account's conversation (rights lifted)
 * and makes its model calls as the conversation's account.
 */
#[AsCommand(
    name: 'app:ai-agent:compact',
    description: 'Run (sync) + visualize/evaluate the hierarchical compaction tree of a persisted agent conversation.',
)]
class AgentCompactConversationCommand extends AgentConsoleCommand
{
    use ContextRetentionSettingsOptionsTrait;

    protected function configure(): void
    {
        $this
            ->addArgument('conversationId', InputArgument::REQUIRED, 'The persisted conversation id to compact / evaluate')
            ->addOption('show-only', null, InputOption::VALUE_NONE, 'Do NOT run a compaction round — just render the existing tree + cover')
            ->addOption('as-of-message', null, InputOption::VALUE_OPTIONAL, 'Render the COVER as it was when this message index was produced')
            ->addOption('validate', null, InputOption::VALUE_NONE, 'Validate the persisted tree and context build: every bounded loader (cover, frontier, tail) equals the full-scan reference, the cover tiles without gaps, nothing is lost; prints the efficiency counts');
        // the command is about compaction: its knobs carry no prefix (`--trigger-tokens`, `--keep-messages`, …)
        $this->addCompactionSettingsOptions('');
        $this->configureIgnoreBudgetOption();
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Loads the conversation, runs a round unless `--show-only`, then validates or renders tree and cover.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(600);

        $conversationId = (int)$input->getArgument('conversationId');
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        /** @var AIConversation|null $conversation */
        $conversation = $aIConversationsService->find($conversationId);
        if ($conversation === null) {
            $output->writeln("<error>No conversation found with id $conversationId.</error>");
            return Command::FAILURE;
        }
        $compactionSettingsOverride = $this->buildCompactionSettingsOverrideFromInput($input, '');

        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessage::getService();
        $conversation->messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false)
            ?? AIConversationMessages::newInstance();
        $conversation->compactionNodes = $conversation->findCompactionNodesInOrder() ?? AIConversationCompactionNodes::newInstance();
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        $this->actAsAccount($conversation->account ?? null);

        if ($compactionSettingsOverride !== null) {
            $conversation->compactionSettings = $compactionSettingsOverride->applyTo($conversation->compactionSettings ?? null);
        }

        $aIConversationCompactionService = $this->getAIConversationCompactionService();
        if (!(bool)$input->getOption('show-only')) {
            $this->runCompactionRound($conversation, $aIConversationCompactionService, $output);
        }

        if ((bool)$input->getOption('validate')) {
            $consistencyReport = $aIConversationCompactionService->validateCompactionConsistency($conversation);
            $this->renderValidation($consistencyReport, $output);
            return $consistencyReport->isAllPass() ? Command::SUCCESS : Command::FAILURE;
        }

        $this->renderTree($conversation, $output);
        $this->renderCover($conversation, $output, $this->resolveAsOf($conversation, $input));
        return Command::SUCCESS;
    }

    /**
     * One synchronous compaction round inside the compaction envelope of the conversation's account; a failure is
     * printed, the tree is rendered anyway.
     *
     * @param AIConversation $conversation
     * @param AIConversationCompactionService $aIConversationCompactionService
     * @param OutputInterface $output
     * @return void
     */
    protected function runCompactionRound(AIConversation $conversation, AIConversationCompactionService $aIConversationCompactionService, OutputInterface $output): void
    {
        $nodeCountBefore = count($conversation->compactionNodes?->getElements() ?? []);
        try {
            $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION);
            try {
                $aIConversationCompactionService->compactConversation($conversation, async: false);
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
        } catch (Throwable $compactionError) {
            $output->writeln("<error>compaction round failed: {$compactionError->getMessage()}</error>");
        }
        $createdNodeCount = count($conversation->compactionNodes?->getElements() ?? []) - $nodeCountBefore;
        $output->writeln(sprintf('<info>compaction round complete — %d new node(s) created.</info>', $createdNodeCount));
    }

    /**
     * @param AIConversationCompactionConsistencyReport $consistencyReport
     * @param OutputInterface $output
     * @return void
     */
    protected function renderValidation(AIConversationCompactionConsistencyReport $consistencyReport, OutputInterface $output): void
    {
        $output->writeln("\n<comment>CONTEXT-BUILD VALIDATION — conversation {$consistencyReport->conversationId}</comment>");
        foreach ($consistencyReport->getChecks() as $consistencyCheck) {
            $detailTexts = [];
            foreach ($consistencyCheck->getDetails() as $detailName => $detailValue) {
                $detailTexts[] = "$detailName=" . (is_scalar($detailValue) ? (string)$detailValue : json_encode($detailValue));
            }
            $output->writeln(($consistencyCheck->isPass() ? '  <info>PASS</info> ' : '  <error>FAIL</error> ') . $consistencyCheck->getCheckName() . ($detailTexts !== [] ? ' (' . implode(', ', $detailTexts) . ')' : ''));
        }
        $output->writeln(sprintf(
            "\n<comment>EFFICIENCY</comment>  total nodes=%d → cover loaded=%d, frontier loaded=%d  |  total messages=%d → tail loaded=%d",
            $consistencyReport->totalNodes,
            $consistencyReport->coverNodesLoaded,
            $consistencyReport->frontierNodesLoaded,
            $consistencyReport->totalMessages,
            $consistencyReport->tailMessagesLoaded
        ));
        $output->writeln(($consistencyReport->isAllPass() ? '<info>ALL CHECKS PASS</info>' : '<error>VALIDATION FAILED</error>'));
    }

    /**
     * The as-of point of the cover: the send time of the model call of `--as-of-message`, or null (now).
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @return DateTime|null
     */
    protected function resolveAsOf(AIConversation $conversation, InputInterface $input): ?DateTime
    {
        $asOfMessageIndex = $input->getOption('as-of-message');
        if ($asOfMessageIndex === null) {
            return null;
        }
        foreach ($conversation->messages?->getElements() ?? [] as $message) {
            if (($message->indexInConversation ?? -1) === (int)$asOfMessageIndex) {
                return $message->requestSnapshot->sentAt ?? null;
            }
        }
        return null;
    }

    /**
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     */
    protected function renderTree(AIConversation $conversation, OutputInterface $output): void
    {
        $compactionNodes = $conversation->compactionNodes?->getElements() ?? [];
        $output->writeln("\n<comment>Compaction tree — " . count($compactionNodes) . ' node(s)</comment>');
        if ($compactionNodes === []) {
            $output->writeln('<fg=gray>(empty — nothing compacted)</>');
            return;
        }
        usort($compactionNodes, static function (AIConversationCompactionNode $firstNode, AIConversationCompactionNode $secondNode): int {
            $byLevel = ($secondNode->level ?? 0) <=> ($firstNode->level ?? 0);
            return $byLevel !== 0 ? $byLevel : (($firstNode->coveredFromMessageIndex ?? 0) <=> ($secondNode->coveredFromMessageIndex ?? 0));
        });
        $treeTable = new Table($output);
        $treeTable->setHeaders(['Level', 'Range', 'Msgs', 'Source', 'Model', 'Summary preview']);
        foreach ($compactionNodes as $compactionNode) {
            $treeTable->addRow([
                (string)($compactionNode->level ?? '?'),
                sprintf('%d–%d', $compactionNode->coveredFromMessageIndex ?? 0, $compactionNode->coveredToMessageIndex ?? 0),
                (string)($compactionNode->coveredMessageCount ?? '?'),
                ($compactionNode->fromRawMessages ?? false) ? 'raw leaf' : 'roll-up',
                (string)($compactionNode->aiModelName ?? '—'),
                $this->buildPreview($compactionNode->summaryText ?? '', 80),
            ]);
        }
        $treeTable->render();
    }

    /**
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @param DateTime|null $asOf
     * @return void
     */
    protected function renderCover(AIConversation $conversation, OutputInterface $output, ?DateTime $asOf): void
    {
        $coverLabel = $asOf !== null ? ' (as of ' . $asOf->format('Y-m-d H:i:s') . ')' : '';
        $output->writeln("\n<comment>Model-facing cover$coverLabel</comment>");
        $compactionCover = $conversation->buildCompactionCover($asOf);
        $coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex($asOf);
        if ($compactionCover->isEmpty()) {
            $output->writeln('<fg=gray>(no cover — the whole conversation is sent raw)</>');
            return;
        }
        foreach ($compactionCover->getCoverNodes() as $coverNode) {
            $output->writeln(sprintf(
                '  <fg=cyan>▸ [messages %d–%d · level %d]</> %s',
                $coverNode->coveredFromMessageIndex ?? 0,
                $coverNode->coveredToMessageIndex ?? 0,
                $coverNode->level ?? 0,
                $this->buildPreview($coverNode->summaryText ?? '', 100)
            ));
        }
        $highestMessageIndex = $this->findHighestMessageIndex($conversation);
        $rawTailMessageCount = $coveredUpToIndex !== null && $highestMessageIndex !== null ? max(0, $highestMessageIndex - $coveredUpToIndex) : 0;
        $output->writeln(sprintf(
            '<info>cover edge: message %s · raw verbatim tail: %d message(s)</info>',
            $coveredUpToIndex !== null ? (string)$coveredUpToIndex : '?',
            $rawTailMessageCount
        ));
    }

    /**
     * @param AIConversation $conversation
     * @return int|null the highest message index of the loaded messages
     */
    protected function findHighestMessageIndex(AIConversation $conversation): ?int
    {
        $highestMessageIndex = null;
        foreach ($conversation->messages?->getElements() ?? [] as $message) {
            $indexInConversation = $message->indexInConversation ?? null;
            if ($indexInConversation !== null && ($highestMessageIndex === null || $indexInConversation > $highestMessageIndex)) {
                $highestMessageIndex = $indexInConversation;
            }
        }
        return $highestMessageIndex;
    }

    /**
     * @param string $text
     * @param int $maximumLength
     * @return string the text on one line, cut with `…`
     */
    protected function buildPreview(string $text, int $maximumLength): string
    {
        $oneLineText = (string)preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($oneLineText) > $maximumLength ? mb_substr($oneLineText, 0, $maximumLength - 1) . '…' : $oneLineText;
    }

    /** @return AIConversationCompactionService */
    protected function getAIConversationCompactionService(): AIConversationCompactionService
    {
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        return $aIConversationCompactionService;
    }
}
