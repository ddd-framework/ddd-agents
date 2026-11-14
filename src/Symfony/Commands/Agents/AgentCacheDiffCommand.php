<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheDiffReport;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The prompt-cache diff of a conversation ({@see PromptCacheDiffReport}): per model call, the first difference to the
 * previous call (the tools, the system prompt with its first changed segment, which wire message — appended or
 * changed), the provider's cache credit, the gap since the previous send and the cause class — and a summary by
 * cause. Every call's slim request snapshot carries the segment and tool hashes; the wire-message column needs the
 * stored debug payload (a conversation with `storeRequestSnapshot` on). Read-only, rights lifted.
 *
 *   php bin/console app:ai-agent:cache-diff 116798
 *   php bin/console app:ai-agent:cache-diff 116798 --json
 */
#[AsCommand(name: 'app:ai-agent:cache-diff', description: 'Prompt-cache diff of a conversation: where each model call first differs from the previous one (tools, system segment, wire message), its cache credit and the cause class.')]
class AgentCacheDiffCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('conversationId', InputArgument::REQUIRED, 'The conversation id (a stored debug payload adds the wire-message diff)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the report as JSON instead of the table');
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Builds the diff report of the conversation's model calls and prints it.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $conversationId = (int)$input->getArgument('conversationId');
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        /** @var AIConversation|null $conversation */
        $conversation = $aIConversationsService->find($conversationId);
        if ($conversation === null) {
            $output->writeln("<error>No conversation found with id $conversationId.</error>");
            return Command::FAILURE;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessage::getService();
        $messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false)
            ?? AIConversationMessages::newInstance();
        $promptCacheDiffReport = PromptCacheDiffReport::fromMessages($messages);
        if ($promptCacheDiffReport->calls === 0) {
            $output->writeln('<comment>No model call carries segment hashes or a debug payload — the conversation needs contextRetentionSettings.storeRequestSnapshot=true for older calls.</comment>');
            return Command::FAILURE;
        }
        if ((bool)$input->getOption('json')) {
            $output->writeln((string)json_encode($promptCacheDiffReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }
        $this->renderCallTable($promptCacheDiffReport, $output);
        $this->renderSummary($promptCacheDiffReport, $output);
        return Command::SUCCESS;
    }

    /**
     * One table row per model call.
     *
     * @param PromptCacheDiffReport $promptCacheDiffReport
     * @param OutputInterface $output
     * @return void
     */
    protected function renderCallTable(PromptCacheDiffReport $promptCacheDiffReport, OutputInterface $output): void
    {
        $callTable = new Table($output);
        $callTable->setHeaders(['idx', 'input', 'cached', 'write', 'gap s', 'provider', 'tools', 'system', 'segment', 'first diff', 'cause', 'state']);
        foreach ($promptCacheDiffReport->rows as $callRow) {
            $firstDifference = $callRow['firstDiff'];
            $callTable->addRow([
                (string)($callRow['index'] ?? '?'),
                (string)$callRow['inputTokens'],
                $callRow['cachedInputTokens'] === 0 ? '<fg=red>0</>' : (string)$callRow['cachedInputTokens'],
                (string)$callRow['cacheWriteTokens'],
                $callRow['gapSeconds'] === null ? '-' : (string)$callRow['gapSeconds'],
                (string)($callRow['provider'] ?? '?'),
                $callRow['toolsChanged'] ? '<fg=yellow>changed</>' : 'same',
                $callRow['systemChanged'] ? '<fg=red>changed</>' : 'same',
                (string)($callRow['firstChangedSystemSegment'] ?? '—'),
                $firstDifference === null ? '—' : sprintf('msg%d %s %s: %s', $firstDifference['position'], $firstDifference['role'], $firstDifference['kind'], mb_substr($firstDifference['head'], 0, 48)),
                $callRow['cause'],
                // `@<epoch start>` (`!<cause>` on the call that started the epoch) and the state notes the call delivered
                $callRow['stateEpochStartIndex'] === null ? '—' : '@' . $callRow['stateEpochStartIndex']
                    . ($callRow['stateEpochCause'] !== null ? ' !' . $callRow['stateEpochCause'] : '')
                    . ($callRow['stateNoteKinds'] !== [] ? ' ' . implode(',', $callRow['stateNoteKinds']) : ''),
            ]);
        }
        $callTable->render();
    }

    /**
     * The totals, the hit rate per cause and the state epochs and notes.
     *
     * @param PromptCacheDiffReport $promptCacheDiffReport
     * @param OutputInterface $output
     * @return void
     */
    protected function renderSummary(PromptCacheDiffReport $promptCacheDiffReport, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            "\ncalls %d · input %d · cached %d (<info>%d %%</info>) · zero-credit calls %d",
            $promptCacheDiffReport->calls,
            $promptCacheDiffReport->inputTokens,
            $promptCacheDiffReport->cachedInputTokens,
            $promptCacheDiffReport->hitPercent(),
            $promptCacheDiffReport->zeroCreditCalls
        ));
        foreach ($promptCacheDiffReport->callsByCause as $cause => $callCount) {
            $inputTokensOfCause = $promptCacheDiffReport->inputByCause[$cause] ?? 0;
            $output->writeln(sprintf(
                '  %-22s %2d call(s) · hit %d %%',
                $cause,
                $callCount,
                $inputTokensOfCause > 0 ? (int)round(100 * ($promptCacheDiffReport->cachedByCause[$cause] ?? 0) / $inputTokensOfCause) : 0
            ));
        }
        if ($promptCacheDiffReport->stateEpochStartsByCause === [] && $promptCacheDiffReport->stateNotesByKind === []) {
            return;
        }
        $output->writeln(sprintf(
            '  state epochs: %s · notes: %s · %d chars (≈ %d tokens, each sent once)',
            $this->describeCounts($promptCacheDiffReport->stateEpochStartsByCause) ?: '—',
            $this->describeCounts($promptCacheDiffReport->stateNotesByKind) ?: '—',
            $promptCacheDiffReport->stateNoteChars,
            (int)ceil($promptCacheDiffReport->stateNoteChars / 4)
        ));
    }

    /**
     * @param array<string, int> $countsByName
     * @return string `name count` pairs, comma-separated
     */
    protected function describeCounts(array $countsByName): string
    {
        $countTexts = [];
        foreach ($countsByName as $countName => $count) {
            $countTexts[] = $countName . ' ' . $count;
        }
        return implode(', ', $countTexts);
    }
}
