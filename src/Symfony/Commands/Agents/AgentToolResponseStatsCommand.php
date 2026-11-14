<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseStats;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shows where the agent's context tokens go in tool results —
 * per-tool response-size totals, the largest single responses of one tool, and the full payload of one tool-call
 * message — so a fat tool response can be found, inspected, and then optimised at the source (`toLlmMarkdown`
 * output format, `#[HideProperty]`, TOON, tighter query scoping). Reads the STORED generated `virtual*` tool-size
 * columns on the messages table; the payload dump loads the message itself. Read-only, rights lifted (a read
 * across every account).
 *
 * Three drill-down levels:
 *   1. RANKING (default)          — totals per tool over the range, sortable via --order-by (default: avg chars).
 *   2. --tool=<name>              — the LARGEST single responses of that tool (message/conversation ids + sizes).
 *   3. --message=<id>             — the full arguments + result payload of one tool-call message.
 *
 *   php bin/console app:ai-agent:tool-response-stats                          # ranking, last 30 days, by avg chars
 *   php bin/console app:ai-agent:tool-response-stats --order-by=max --days=7
 *   php bin/console app:ai-agent:tool-response-stats --tool=searchDocuments --samples=10
 *   php bin/console app:ai-agent:tool-response-stats --message=812345         # the payload to optimise
 */
#[AsCommand(
    name: 'app:ai-agent:tool-response-stats',
    description: 'Analyse agent tool-response sizes: per-tool totals ranking, largest responses of one tool, full payload of one tool-call message.',
)]
class AgentToolResponseStatsCommand extends AgentConsoleCommand
{
    /** @var string The default ranking measure */
    protected const string ORDER_BY_AVG = AgentToolResponseStats::MEASURE_AVG_RESULT_CHARS;

    /** @var int Estimated tokens = chars ÷ 4 (no usage record exists per tool call; the turn's envelope owns the spend) */
    protected const int CHARS_PER_TOKEN = 4;

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Range = the last N days up to today (ignored when --from/--to given)', '30')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Range start day (Y-m-d, inclusive)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Range end day (Y-m-d, inclusive; default today)')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Ranking sort: avg | max | sum | calls (result chars / call count)', self::ORDER_BY_AVG)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max ranking rows shown', '30')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Drill down into ONE tool: its largest single responses')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'How many largest responses to list with --tool', '20')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Dump ONE tool-call message\'s full arguments + result payload')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'With --message: print only the first N chars of the result (default: full)');
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Prints the ranking, the largest responses of one tool, or one payload.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');

        $messageId = $input->getOption('message');
        if ($messageId !== null) {
            return $this->dumpToolCallMessagePayload((int)$messageId, $input, $output);
        }

        $timeInterval = $this->resolveRange($input, $output);
        if ($timeInterval === null) {
            return Command::FAILURE;
        }
        $rangeFrom = $timeInterval->startDate;
        $rangeTo = $timeInterval->endDate;
        $output->writeln("<fg=gray>range: {$rangeFrom->format('Y-m-d')} … {$rangeTo->format('Y-m-d')} · est. tokens = chars ÷ 4</>");

        $toolName = $input->getOption('tool');
        if ($toolName !== null && $toolName !== '') {
            return $this->printLargestToolResponses((string)$toolName, $rangeFrom, $rangeTo, $input, $output);
        }
        return $this->printToolTotalsRanking($rangeFrom, $rangeTo, $input, $output);
    }

    /**
     * Level 1 — the "which tool must be optimised" ranking: totals per tool, sorted by the requested measure
     * (default AVG result chars — systematically fat responses; MAX finds outlier payloads).
     */
    protected function printToolTotalsRanking(DateTime $rangeFrom, DateTime $rangeTo, InputInterface $input, OutputInterface $output): int
    {
        $orderBy = (string)$input->getOption('order-by');
        $rankingRowLimit = max(1, (int)$input->getOption('limit'));

        $aiConversationMessagesService = $this->getAIConversationMessagesService();
        try {
            $agentToolResponseStats = $aiConversationMessagesService->findToolResponseStats($rangeFrom, $rangeTo);
        } catch (Throwable $throwable) {
            $output->writeln('<error>Tool-size aggregation failed — are the virtual* tool-size columns applied on this database? ' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!count($agentToolResponseStats->getElements())) {
            $output->writeln('<comment>No tool calls in the range.</comment>');
            return Command::SUCCESS;
        }

        if (!AgentToolResponseStats::isKnownMeasure($orderBy)) {
            $output->writeln("<error>--order-by must be one of: avg, max, sum, calls (got '$orderBy').</error>");
            return Command::FAILURE;
        }
        $toolResponseStats = $agentToolResponseStats->getStatsSortedByMeasure($orderBy);

        $rankingTable = new Table($output);
        $rankingTable->setHeaders(['#', 'tool', 'calls', 'Ø chars', 'Ø tok', 'max chars', 'max tok', 'Σ chars', 'Σ tok']);
        foreach (array_slice($toolResponseStats, 0, $rankingRowLimit) as $rankingIndex => $toolResponseStat) {
            $rankingTable->addRow([
                $rankingIndex + 1,
                $toolResponseStat->toolName,
                number_format($toolResponseStat->callCount),
                number_format($toolResponseStat->avgResultChars),
                number_format($this->estimatedTokensOf($toolResponseStat->avgResultChars)),
                number_format($toolResponseStat->maxResultChars),
                number_format($this->estimatedTokensOf($toolResponseStat->maxResultChars)),
                number_format($toolResponseStat->sumResultChars),
                number_format($this->estimatedTokensOf($toolResponseStat->sumResultChars)),
            ]);
        }
        $rankingTable->render();
        if (count($toolResponseStats) > $rankingRowLimit) {
            $output->writeln('<fg=gray>… ' . (count($toolResponseStats) - $rankingRowLimit) . ' more tools — raise --limit</>');
        }
        $output->writeln('<fg=gray>drill down: --tool=<name> · sort: --order-by=avg|max|sum|calls</>');
        return Command::SUCCESS;
    }

    /**
     * Level 2 — the largest single responses of ONE tool: the concrete messages/conversations to inspect before
     * deciding the optimisation.
     */
    protected function printLargestToolResponses(string $toolName, DateTime $rangeFrom, DateTime $rangeTo, InputInterface $input, OutputInterface $output): int
    {
        $sampleLimit = max(1, (int)$input->getOption('samples'));

        $aiConversationMessagesService = $this->getAIConversationMessagesService();
        try {
            $agentToolResponseSamples = $aiConversationMessagesService->findLargestToolResponseSamples($toolName, $rangeFrom, $rangeTo, $sampleLimit);
        } catch (Throwable $throwable) {
            $output->writeln('<error>Tool-size query failed — are the virtual* tool-size columns applied on this database? ' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!count($agentToolResponseSamples->getElements())) {
            $output->writeln("<comment>No '$toolName' calls in the range (tool names are exact — check the ranking).</comment>");
            return Command::SUCCESS;
        }

        $output->writeln("<fg=cyan>largest '$toolName' responses:</>");
        $samplesTable = new Table($output);
        $samplesTable->setHeaders(['messageId', 'conversationId', 'when', 'result chars', 'result tok', 'args chars']);
        foreach ($agentToolResponseSamples->getElements() as $toolResponseSample) {
            $samplesTable->addRow([
                $toolResponseSample->messageId,
                $toolResponseSample->conversationId,
                $toolResponseSample->created?->format('Y-m-d H:i') ?? '–',
                number_format($toolResponseSample->resultChars),
                number_format($this->estimatedTokensOf($toolResponseSample->resultChars)),
                number_format($toolResponseSample->argumentsChars),
            ]);
        }
        $samplesTable->render();
        $output->writeln('<fg=gray>payload: --message=<messageId></>');
        return Command::SUCCESS;
    }

    /**
     * Level 3 — the full arguments + result payload of ONE tool-call message: the material the optimisation
     * (`toLlmMarkdown`, Hide, TOON, query scoping) is decided from.
     */
    protected function dumpToolCallMessagePayload(int $messageId, InputInterface $input, OutputInterface $output): int
    {
        /** @var AIConversationMessage|null $toolCallMessage */
        $toolCallMessage = $this->getAIConversationMessagesService()->find($messageId);
        if ($toolCallMessage === null) {
            $output->writeln("<error>No conversation message found with id $messageId.</error>");
            return Command::FAILURE;
        }
        if (!isset($toolCallMessage->toolCall)) {
            $output->writeln("<error>Message $messageId carries no tool call.</error>");
            return Command::FAILURE;
        }

        $toolCall = $toolCallMessage->toolCall;
        $toolResultText = $toolCall->result ?? '';
        $output->writeln("<fg=cyan>message $messageId · conversation {$toolCallMessage->aiConversationId} · tool {$toolCall->name} · status " . ($toolCall->status ?? '–') . '</>');
        $output->writeln("\n<fg=yellow>── ARGUMENTS ──</>");
        $output->writeln(json_encode($toolCall->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $headChars = $input->getOption('head') !== null ? max(1, (int)$input->getOption('head')) : null;
        $resultChars = mb_strlen($toolResultText);
        $output->writeln("\n<fg=yellow>── RESULT ──</> <fg=gray>(" . number_format($resultChars) . ' chars ≈ ' . number_format($this->estimatedTokensOf($resultChars)) . ' tokens)</>');
        if ($headChars !== null && $resultChars > $headChars) {
            $output->writeln(mb_substr($toolResultText, 0, $headChars));
            $output->writeln('<fg=gray>… truncated at ' . number_format($headChars) . ' of ' . number_format($resultChars) . ' chars (drop --head for the full payload)</>');
        } else {
            $output->writeln($toolResultText);
        }
        return Command::SUCCESS;
    }

    /**
     * Resolves --days / --from / --to into full-day bounds: the start at 00:00:00 of the first day, the end at
     * 23:59:59 of the last; prints the error and returns null on an unparseable day or an inverted range.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return TimeInterval|null the range, both bounds set; null after an error
     */
    protected function resolveRange(InputInterface $input, OutputInterface $output): ?TimeInterval
    {
        try {
            $rangeToDay = $input->getOption('to') !== null
                ? new DateTime(((string)$input->getOption('to')) . ' 23:59:59')
                : new DateTime('today 23:59:59');
            $rangeFromDay = $input->getOption('from') !== null
                ? new DateTime(((string)$input->getOption('from')) . ' 00:00:00')
                : (clone $rangeToDay)->modify('-' . (max(1, (int)$input->getOption('days')) - 1) . ' days')->setTime(0, 0);
        } catch (Throwable) {
            $output->writeln('<error>--from/--to must be valid Y-m-d days.</error>');
            return null;
        }
        if ($rangeFromDay > $rangeToDay) {
            $output->writeln('<error>--from must not be after --to.</error>');
            return null;
        }
        return new TimeInterval($rangeFromDay, $rangeToDay);
    }

    /**
     * @param int $chars
     * @return int the estimated tokens of `$chars` characters
     */
    protected function estimatedTokensOf(int $chars): int
    {
        return (int)round($chars / self::CHARS_PER_TOKEN);
    }

    /** @return AIConversationMessagesService */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
