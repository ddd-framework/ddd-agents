<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentActivityLineElementReplay;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentActivityLineModelReplay;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentActivityLineModelReplays;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentActivityLineTurnReplay;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Replays the tool calls of a persisted conversation through the activity narrator and prints the lines an owner
 * would have read, per model: the started moment (arguments only) and the finished moment (with the result head), in
 * batches of the endpoint's size. For a model comparison, pass several models
 * (`--models=VENDOR.MODEL_A,VENDOR.MODEL_B`) and read the lines, the latency and the tokens side by side. Read-only on
 * the conversation; the narrator calls are booked under the activity envelope of the conversation's account.
 *
 *   php bin/console app:ai-agent:activity-lines --conversation=123
 *   php bin/console app:ai-agent:activity-lines --conversation=123 --endpoint
 */
#[AsCommand(
    name: 'app:ai-agent:activity-lines',
    description: 'Replay a conversation\'s tool calls through the activity narrator and print the owner-facing lines per model, with latency and tokens.'
)]
class AgentActivityLinesCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('conversation', null, InputOption::VALUE_REQUIRED, 'The conversation id to replay')
            ->addOption('models', null, InputOption::VALUE_OPTIONAL, 'Comma-separated catalog model names to compare; empty = the narrator\'s model scope decides', '')
            ->addOption('turn', null, InputOption::VALUE_OPTIONAL, 'Only the turn that starts at this indexInConversation; default: every turn', null)
            ->addOption('effort', null, InputOption::VALUE_OPTIONAL, 'Reasoning effort override for the comparison (none, minimal, low, medium); empty = the attribute decides', '')
            ->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Elements per narrator call (default: the service constant)', '')
            ->addOption('endpoint', null, InputOption::VALUE_NONE, 'Run the endpoint\'s read (cache, lock, ceilings, one narrator call) for the newest turn instead of the replay')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->addOption('show-payload', null, InputOption::VALUE_NONE, 'Print the exact prompt and user content of every narrator call');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Runs the endpoint read or the replay per model.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(1800);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $conversationId = (int)$input->getOption('conversation');
        if ($conversationId <= 0) {
            $symfonyStyle->error('--conversation=<id> is required.');
            return Command::FAILURE;
        }
        $modelNames = array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('models')))));
        if ($modelNames === []) {
            $modelNames = [null];
        }
        $reasoningEffortOverride = trim((string)$input->getOption('effort')) ?: null;
        $turnOption = $input->getOption('turn');
        $onlyTurnIndex = $turnOption !== null && $turnOption !== '' ? (int)$turnOption : null;

        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $conversation = $aIConversationsService->find($conversationId);
            if (!$conversation instanceof AIConversation) {
                $symfonyStyle->error("Conversation $conversationId not found.");
                return Command::FAILURE;
            }
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $conversation->messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation) ?? AIConversationMessages::newInstance();
            /** @var AgentActivityLinesService $agentActivityLinesService */
            $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
            if ((int)$input->getOption('batch') > 0) {
                $agentActivityLinesService->elementsPerCall = (int)$input->getOption('batch');
            }

            if ((bool)$input->getOption('endpoint')) {
                $this->runEndpointRead($conversation, $agentActivityLinesService, $onlyTurnIndex, $symfonyStyle);
                return Command::SUCCESS;
            }

            $turnStartIndexes = $onlyTurnIndex !== null ? [$onlyTurnIndex] : $this->findTurnStartIndexes($conversation);
            $modelReplays = new AgentActivityLineModelReplays();
            foreach ($modelNames as $comparisonPosition => $modelName) {
                $modelReplay = $this->replayTurnsWithModel(
                    $conversation,
                    $agentActivityLinesService,
                    $turnStartIndexes,
                    $comparisonPosition,
                    $modelName,
                    $reasoningEffortOverride,
                    $input,
                    $symfonyStyle
                );
                $modelReplays->add($modelReplay);
            }
            if ((bool)$input->getOption('json')) {
                $output->writeln((string)json_encode($this->buildJsonReport($conversationId, $modelReplays), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            return Command::SUCCESS;
        } catch (Throwable $replayError) {
            $symfonyStyle->error($replayError->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * The endpoint's read of one turn (cache, lock, ceilings, one narrator call) and its lines.
     *
     * @param AIConversation $conversation
     * @param AgentActivityLinesService $agentActivityLinesService
     * @param int|null $onlyTurnIndex the turn to read; null = the newest
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function runEndpointRead(AIConversation $conversation, AgentActivityLinesService $agentActivityLinesService, ?int $onlyTurnIndex, SymfonyStyle $symfonyStyle): void
    {
        $turnMessageId = $agentActivityLinesService->resolveTurnMessageId(
            $conversation,
            $onlyTurnIndex === null ? null : $this->findMessageIdAtIndex($conversation, $onlyTurnIndex)
        );
        $startedAt = microtime(true);
        $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_ACTIVITY);
        try {
            $activityLines = $agentActivityLinesService->findOrNarrateLinesForTurn($conversation, $turnMessageId);
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
        $symfonyStyle->text(sprintf(
            'turn message #%s, running=%s, %.2f s',
            $turnMessageId ?? '-',
            $agentActivityLinesService->isTurnRunning($conversation, $activityLines) ? 'yes' : 'no',
            microtime(true) - $startedAt
        ));
        foreach ($activityLines->getElements() as $activityLine) {
            $symfonyStyle->text(sprintf(
                '  #%d %-9s %-11s %s%s',
                $activityLine->toolCallMessageId,
                $activityLine->state,
                $activityLine->kind,
                $activityLine->helperIndex !== null ? "[helper {$activityLine->helperIndex}] " : '',
                $activityLine->line ?? '(no line yet)'
            ));
        }
    }

    /**
     * Narrates every turn with one model, prints the lines and returns the model's replay.
     *
     * @param AIConversation $conversation
     * @param AgentActivityLinesService $agentActivityLinesService
     * @param int[] $turnStartIndexes
     * @param int $comparisonPosition the place of the model in the comparison, from 0
     * @param string|null $modelName null = the narrator's attribute decides
     * @param string|null $reasoningEffortOverride
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @return AgentActivityLineModelReplay the lines and figures of the model, as printed with `--json`
     */
    protected function replayTurnsWithModel(
        AIConversation $conversation,
        AgentActivityLinesService $agentActivityLinesService,
        array $turnStartIndexes,
        int $comparisonPosition,
        ?string $modelName,
        ?string $reasoningEffortOverride,
        InputInterface $input,
        SymfonyStyle $symfonyStyle
    ): AgentActivityLineModelReplay {
        $printsJson = (bool)$input->getOption('json');
        $modelKey = ($modelName ?? 'attribute default') . ($reasoningEffortOverride !== null ? " effort={$reasoningEffortOverride}" : '');
        $symfonyStyle->section('Model ' . $modelKey);
        $modelReplay = AgentActivityLineModelReplay::forModel($comparisonPosition, $modelKey);
        foreach ($turnStartIndexes as $turnIndex) {
            $finishedActivityLines = $this->keepOnlyElementsOfTurn(
                $agentActivityLinesService->buildElementsForTurn($conversation, $turnIndex),
                $conversation,
                $turnIndex,
                $turnStartIndexes
            );
            if ($finishedActivityLines->count() === 0) {
                continue;
            }
            $turnReplay = AgentActivityLineTurnReplay::forTurnIndex($turnIndex);
            $activityLinesByMoment = [
                AgentActivityLineElementReplay::MOMENT_STARTED => $this->buildStartedActivityLines($finishedActivityLines),
                AgentActivityLineElementReplay::MOMENT_FINISHED => $finishedActivityLines,
            ];
            foreach ($activityLinesByMoment as $moment => $activityLinesOfMoment) {
                $modelReplay->narratorCallCount += (int)ceil($activityLinesOfMoment->withoutLine()->count() / $agentActivityLinesService->elementsPerCall);
                $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_ACTIVITY);
                try {
                    $agentActivityLinesService->narrate($conversation, $activityLinesOfMoment, $turnIndex, $modelName, $reasoningEffortOverride);
                } finally {
                    $this->closeUsageEnvelope($usageEnvelopeContext);
                }
                $modelReplay->inputTokens += $agentActivityLinesService->lastInputTokens ?? 0;
                $modelReplay->outputTokens += $agentActivityLinesService->lastOutputTokens ?? 0;
                $modelReplay->costUsd += $agentActivityLinesService->lastCostInUSD ?? 0.0;
                $modelReplay->seconds += $agentActivityLinesService->lastDurationSeconds ?? 0.0;
                $modelReplay->modelName = $agentActivityLinesService->lastModelName ?? $modelKey;
                if ((bool)$input->getOption('show-payload') && $agentActivityLinesService->lastPayloadSample !== null) {
                    $symfonyStyle->text("--- payload of the last narrator call, turn @{$turnIndex} {$moment} ---\n" . $agentActivityLinesService->lastPayloadSample);
                }
                foreach ($agentActivityLinesService->lastUnparsedResults as $unparsedResult) {
                    $symfonyStyle->warning("Turn @{$turnIndex} {$moment}: answer was not the expected JSON:\n" . $unparsedResult);
                }
                $this->recordLinesOfMoment($activityLinesOfMoment, $moment, $turnReplay, $modelReplay);
            }
            $modelReplay->turnReplays->add($turnReplay);
            if (!$printsJson) {
                $this->printTurnReplay($turnReplay, $symfonyStyle);
            }
        }
        if (!$printsJson) {
            $symfonyStyle->text(sprintf(
                '  %s: %d lines, %d missing, %d narrator calls, %.1f s, %d in / %d out tokens, $%.4f',
                $modelReplay->modelName,
                $modelReplay->lineCount,
                $modelReplay->missingLineCount,
                $modelReplay->narratorCallCount,
                $modelReplay->seconds,
                $modelReplay->inputTokens,
                $modelReplay->outputTokens,
                $modelReplay->costUsd
            ));
        }
        return $modelReplay;
    }

    /**
     * Records the narrated lines of one moment on the turn's elements and counts the lines that came back and the ones
     * that were wanted but missing.
     *
     * @param AgentActivityLines $activityLinesOfMoment
     * @param string $moment {@see AgentActivityLineElementReplay::MOMENT_STARTED} or `MOMENT_FINISHED`
     * @param AgentActivityLineTurnReplay $turnReplay
     * @param AgentActivityLineModelReplay $modelReplay
     * @return void
     */
    protected function recordLinesOfMoment(AgentActivityLines $activityLinesOfMoment, string $moment, AgentActivityLineTurnReplay $turnReplay, AgentActivityLineModelReplay $modelReplay): void
    {
        foreach ($activityLinesOfMoment->getElements() as $activityLine) {
            $turnReplay->elementReplays->findOrAddForActivityLine($activityLine)->recordLineOfMoment($moment, $activityLine->line);
            if (!$activityLine->wantsLine()) {
                continue;
            }
            if ($activityLine->line === null) {
                $modelReplay->missingLineCount++;
            } else {
                $modelReplay->lineCount++;
            }
        }
    }

    /**
     * The started moment of a turn: every element that wants a line, as queued, without a result head or a line.
     *
     * @param AgentActivityLines $finishedActivityLines
     * @return AgentActivityLines
     */
    protected function buildStartedActivityLines(AgentActivityLines $finishedActivityLines): AgentActivityLines
    {
        $startedActivityLines = new AgentActivityLines();
        foreach ($finishedActivityLines->getElements() as $finishedActivityLine) {
            if (!$finishedActivityLine->wantsLine()) {
                continue;
            }
            $startedActivityLine = clone $finishedActivityLine;
            $startedActivityLine->state = AgentActivityLine::STATE_QUEUED;
            $startedActivityLine->narratorResultHead = null;
            $startedActivityLine->line = null;
            $startedActivityLines->add($startedActivityLine);
        }
        return $startedActivityLines;
    }

    /**
     * @param AgentActivityLineTurnReplay $turnReplay
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printTurnReplay(AgentActivityLineTurnReplay $turnReplay, SymfonyStyle $symfonyStyle): void
    {
        $symfonyStyle->text("turn @{$turnReplay->turnIndex}");
        foreach ($turnReplay->elementReplays->getElements() as $elementReplay) {
            $symfonyStyle->text(sprintf('  #%d %-28s %-9s %s', $elementReplay->toolCallMessageId, $elementReplay->toolName, $elementReplay->state, $elementReplay->kind));
            $symfonyStyle->text('     started : ' . ($elementReplay->startedLine ?? '(none)'));
            $symfonyStyle->text('     finished: ' . ($elementReplay->finishedLine ?? '(none)'));
        }
    }

    /**
     * The `--json` printout of the replays: per model its figures and turns, per turn its elements with the line of
     * every moment the narrator was asked for (a moment it was not asked for has no key).
     *
     * @param int $conversationId
     * @param AgentActivityLineModelReplays $modelReplays
     * @return array<string, mixed> the structure `json_encode()` prints
     */
    protected function buildJsonReport(int $conversationId, AgentActivityLineModelReplays $modelReplays): array
    {
        $jsonReport = ['conversationId' => $conversationId, 'models' => []];
        foreach ($modelReplays->getElements() as $modelReplay) {
            $turnsJson = [];
            foreach ($modelReplay->turnReplays->getElements() as $turnReplay) {
                $elementsJson = [];
                foreach ($turnReplay->elementReplays->getElements() as $elementReplay) {
                    $elementJson = ['id' => $elementReplay->toolCallMessageId, 'tool' => $elementReplay->toolName, 'state' => $elementReplay->state, 'kind' => $elementReplay->kind];
                    if ($elementReplay->isStartedMomentNarrated) {
                        $elementJson[AgentActivityLineElementReplay::MOMENT_STARTED] = $elementReplay->startedLine;
                    }
                    if ($elementReplay->isFinishedMomentNarrated) {
                        $elementJson[AgentActivityLineElementReplay::MOMENT_FINISHED] = $elementReplay->finishedLine;
                    }
                    $elementsJson[] = $elementJson;
                }
                $turnsJson[] = ['turnIndex' => $turnReplay->turnIndex, 'elements' => $elementsJson];
            }
            $jsonReport['models'][] = [
                'model' => $modelReplay->modelName,
                'turns' => $turnsJson,
                'inputTokens' => $modelReplay->inputTokens,
                'outputTokens' => $modelReplay->outputTokens,
                'costUsd' => $modelReplay->costUsd,
                'seconds' => $modelReplay->seconds,
                'calls' => $modelReplay->narratorCallCount,
                'lines' => $modelReplay->lineCount,
                'missing' => $modelReplay->missingLineCount,
            ];
        }
        return $jsonReport;
    }

    /**
     * @param AIConversation $conversation
     * @return int[] the indexes of the messages that start a turn
     */
    protected function findTurnStartIndexes(AIConversation $conversation): array
    {
        $turnStartIndexes = [];
        foreach ($conversation->messages->getElements() as $message) {
            if (AIConversationMessages::isTurnInitiatorMessage($message)) {
                $turnStartIndexes[] = (int)($message->indexInConversation ?? 0);
            }
        }
        return $turnStartIndexes;
    }

    /**
     * @param AIConversation $conversation
     * @param int $indexInConversation
     * @return int|null the id of the message at that index
     */
    protected function findMessageIdAtIndex(AIConversation $conversation, int $indexInConversation): ?int
    {
        foreach ($conversation->messages->getElements() as $message) {
            if ((int)($message->indexInConversation ?? -1) === $indexInConversation) {
                return (int)($message->id ?? 0);
            }
        }
        return null;
    }

    /**
     * The elements of ONE turn only: rows past the next turn's first message are dropped.
     *
     * @param AgentActivityLines $activityLines
     * @param AIConversation $conversation
     * @param int $turnIndex
     * @param int[] $turnStartIndexes
     * @return AgentActivityLines
     */
    protected function keepOnlyElementsOfTurn(AgentActivityLines $activityLines, AIConversation $conversation, int $turnIndex, array $turnStartIndexes): AgentActivityLines
    {
        $nextTurnIndex = null;
        foreach ($turnStartIndexes as $turnStartIndex) {
            if ($turnStartIndex > $turnIndex && ($nextTurnIndex === null || $turnStartIndex < $nextTurnIndex)) {
                $nextTurnIndex = $turnStartIndex;
            }
        }
        if ($nextTurnIndex === null) {
            return $activityLines;
        }
        $indexInConversationByMessageId = [];
        foreach ($conversation->messages->getElements() as $message) {
            $indexInConversationByMessageId[(int)($message->id ?? 0)] = (int)($message->indexInConversation ?? 0);
        }
        $keptActivityLines = new AgentActivityLines();
        foreach ($activityLines->getElements() as $activityLine) {
            if (($indexInConversationByMessageId[$activityLine->toolCallMessageId] ?? PHP_INT_MAX) < $nextTurnIndex) {
                $keptActivityLines->add($activityLine);
            }
        }
        return $keptActivityLines;
    }
}
