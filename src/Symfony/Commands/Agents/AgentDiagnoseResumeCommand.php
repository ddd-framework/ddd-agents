<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosis;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosisTailRow;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
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
 * Read-only RESUME DIAGNOSER for one persisted {@see AIConversation}: reconstructs — WITHOUT driving a turn or
 * mutating anything — the exact decision {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady()}
 * would take, so a stuck / looping / silently-unanswered conversation is decidable from the console.
 *
 * It replays the claim-preamble's two loads verbatim (the SAME calls the resume path makes at drive time):
 *   1. the compaction COVER nodes → the covered-up-to index,
 *   2. the BOUNDED derive tail (the raw tail past the cover edge when compacted, else the full ordered window) — the
 *      message window the resume path actually reasons over,
 * then evaluates the TWO independent drive triggers on that window ({@see AIConversation::hasUnansweredInboundMessage()}
 * + the oldest settled-unfolded forked batch) and prints the VERDICT branch: WOULD-DRIVE vs noWork.reDerive.
 *
 * The whole point is to expose the class of bug where the BOUNDED derive tail disagrees with the FULL message tail —
 * e.g. a compaction cover edge at/after the newest unanswered owner message makes the derive tail miss it, so the turn
 * either loops (re-fires forever) or parks IDLE with the user's message silently unanswered. Both tails are printed
 * side by side with `hasUnansweredInboundMessage` computed on EACH, so the disagreement is visible at a glance.
 *
 *   php bin/console app:ai-agent:diagnose 2118
 *   php bin/console app:ai-agent:diagnose 2118 --include-truncated
 *
 * The reconstruction itself is {@see AIConversationResumeDiagnosis}. `--drive` and `--retry` mutate (see their
 * option texts). The budget cap is lifted for `--drive` only when the conversation itself carries `budgetBypass`.
 */
#[AsCommand(
    name: 'app:ai-agent:diagnose',
    description: 'Reconstruct (read-only) the resume/derive decision for one agent conversation — why it loops, parks, or leaves a message unanswered.',
)]
class AgentDiagnoseResumeCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('conversationId', InputArgument::REQUIRED, 'The conversation id to diagnose')
            ->addOption('include-truncated', null, InputOption::VALUE_NONE, 'Also count soft-deleted (truncatedAt) messages in the FULL tail')
            ->addOption('drive', null, InputOption::VALUE_NONE, 'After the read-only diagnosis, DRIVE one turn SYNCHRONOUSLY with full console output — and, on failure, the FULL stack trace (no swallow). Bypasses the queue/worker; surfaces the exact error a background worker would hide. Mutates: appends the reply + persists.')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'After the diagnosis, RETRY a FAILED run the way the chat\'s retry button does: reset FAILED → IDLE with an owed turn and dispatch the resume to the workers (needs an unanswered owner message). Mutates the run state; the reply lands asynchronously.');
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Prints the diagnosis, then retries or drives when asked.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $conversationId = (int)$input->getArgument('conversationId');
        $includeTruncated = (bool)$input->getOption('include-truncated');
        if ($includeTruncated) {
            DBAIConversationMessage::$includeTruncated = true;
        }

        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            /** @var AIConversation|null $conversation */
            $conversation = $aIConversationsService->find($conversationId);
            if ($conversation === null) {
                $output->writeln("<error>No conversation found with id $conversationId.</error>");
                return Command::FAILURE;
            }

            /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
            $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
            $diagnosis = AIConversationResumeDiagnosis::buildFor($conversation, $aIConversationDelegationBatchesService);

            $this->printState($diagnosis, $output);
            $this->printCover($diagnosis, $output);
            $this->printTails($diagnosis, $output);
            $this->printVerdict($diagnosis, $output);

            if ((bool)$input->getOption('retry')) {
                /** @var AIConversationsService $aIConversationsService */
                $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
                $retried = $aIConversationsService->retryUnansweredTurn($conversation);
                $output->writeln($retried
                    ? "\n<info>✓ retry dispatched: run re-armed to IDLE with an owed turn, resume queued for the workers (conversation {$conversation->id}).</info>"
                    : "\n<comment>retry refused: the run is neither FAILED nor IDLE, has no unanswered owner message, is a delegation child, or a worker already re-claimed it.</comment>");
            }
            if ((bool)$input->getOption('drive')) {
                $this->driveOneTurnWithFullTrace($conversation, $output);
            }
        } finally {
            if ($includeTruncated) {
                DBAIConversationMessage::$includeTruncated = false;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Drives one turn SYNCHRONOUSLY, inline, with the console attached: every step streams, and an exception reaches the
     * console with its FULL stack trace (a turn worker only logs the message, and a fatal error ends the worker and
     * leaves the conversation RUNNING). Rights are lifted (for the whole run), the budget cap is lifted for the process
     * when the conversation carries `budgetBypass`, the space relations are attached, the turn runs inside the turn
     * envelope of the conversation's account, compaction runs synchronously (the console has no worker). MUTATES the
     * conversation: the reply is appended and stored as a worker would store it.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     */
    protected function driveOneTurnWithFullTrace(AIConversation $conversation, OutputInterface $output): void
    {
        $output->writeln("\n<fg=cyan>══ SYNC DRIVE (one turn, full trace, no swallow) ══</>");

        if ($conversation->budgetBypass) {
            $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
            $aIUsageEnvelopesService->grantBudgetBypassForTheProcess(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
        }
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        // the bounded tail a worker loads too — the synchronous loop reads $conversation->messages directly
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);

        try {
            $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
            try {
                // inline: no asynchronous delegations, no realtime signal, no run token (no claim is held here)
                $aIConversationAgentService->generateAgentReply(
                    $conversation,
                    $output,
                    full: false,
                    debug: false,
                    collapseThreads: false,
                    aiModelName: $conversation->aiModelNameOverride ?? null,
                    timings: true,
                    compactAsync: false,
                );
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
            $output->writeln("\n<fg=green>✓ drive returned without throwing.</> Re-run `app:ai-agent:diagnose {$conversation->id}` to see the appended reply.");
        } catch (Throwable $driveError) {
            $output->writeln("\n<fg=red;options=bold>⛔ DRIVE THREW — this is the error the worker was hiding:</>");
            $output->writeln('<fg=red>' . $driveError::class . ': ' . $driveError->getMessage() . '</>');
            $output->writeln("<fg=gray>{$driveError->getFile()}:{$driveError->getLine()}</>");
            $output->writeln("\n<fg=gray>── stack trace ──</>");
            $output->writeln($driveError->getTraceAsString());
        }
    }

    protected function printState(AIConversationResumeDiagnosis $diagnosis, OutputInterface $output): void
    {
        $output->writeln("<fg=cyan>══ RESUME DIAGNOSIS · CONVERSATION #{$diagnosis->aiConversationId} ══</>");
        $table = new Table($output);
        $table->setStyle('compact');
        foreach ($diagnosis->stateRows as $stateRowPosition => $stateRow) {
            // the first state row is the run state: it is printed with the reading of that state
            $runStateReading = $stateRowPosition !== 0 ? '' : ($diagnosis->runStateIsNonTerminal ? ' <fg=yellow>(NON-TERMINAL)</>' : ' <fg=green>(terminal/idle)</>');
            $table->addRow([$stateRow->label, $stateRow->value . $runStateReading]);
        }
        $table->render();
    }

    protected function printCover(AIConversationResumeDiagnosis $diagnosis, OutputInterface $output): void
    {
        $output->writeln("\n<fg=cyan>══ COMPACTION COVER ══</>");
        if ($diagnosis->coverNodes === []) {
            $output->writeln('<fg=gray>· no compaction cover — whole conversation is raw (coveredUpToIndex = null → derive uses the FULL window)</>');
            return;
        }
        $table = new Table($output);
        $table->setHeaders(['level', 'from', 'to', 'msgCount', 'srcTok', 'sumTok']);
        foreach ($diagnosis->coverNodes as $coverNode) {
            $table->addRow($coverNode->getPrintedValues());
        }
        $table->render();
        $output->writeln(sprintf('<fg=yellow>· coveredUpToIndex = %s</> → derive tail = raw messages with index > %s', (string)$diagnosis->coveredUpToIndex, (string)$diagnosis->coveredUpToIndex));
    }

    protected function printTails(AIConversationResumeDiagnosis $diagnosis, OutputInterface $output): void
    {
        $output->writeln("\n<fg=cyan>══ TAILS — FULL vs BOUNDED-DERIVE ══</>");
        $output->writeln('<fg=gray>The window the resume path actually reasons over is the BOUNDED-DERIVE tail. If it omits the newest owner message that the FULL tail ends on, THAT is the bug.</>');

        $output->writeln("\n<fg=magenta>FULL tail (last 8 of " . $diagnosis->fullTailMessageCount . "):</>");
        $this->renderTailTable($diagnosis->fullTailRows, $output);

        $output->writeln("\n<fg=magenta>BOUNDED-DERIVE tail (" . $diagnosis->deriveTailMessageCount . " messages, index > " . ($diagnosis->coveredUpToIndex ?? 'none') . "):</>");
        if ($diagnosis->deriveTailRows === []) {
            $output->writeln('<fg=red>· EMPTY — the derive path sees NO messages past the cover edge. hasUnansweredInboundMessage() will be FALSE here regardless of the real tail.</>');
        } else {
            $this->renderTailTable($diagnosis->deriveTailRows, $output);
        }
    }

    /** @param AIConversationResumeDiagnosisTailRow[] $tailRows */
    protected function renderTailTable(array $tailRows, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['idx', 'role/type', 'preview']);
        foreach ($tailRows as $tailRow) {
            $table->addRow($tailRow->getPrintedValues());
        }
        $table->render();
    }

    protected function printVerdict(AIConversationResumeDiagnosis $diagnosis, OutputInterface $output): void
    {
        $output->writeln("\n<fg=cyan>══ VERDICT — what resumeConversationIfReady() would do ══</>");
        $output->writeln(sprintf(
            '· hasUnansweredInboundMessage on DERIVE tail : %s',
            $diagnosis->hasUnansweredInboundOnDeriveTail ? '<fg=green>true</>' : '<fg=red>false</>'
        ));
        $output->writeln(sprintf(
            '· hasUnansweredInboundMessage on FULL   tail : %s',
            $diagnosis->hasUnansweredInboundOnFullTail ? '<fg=green>true</>' : '<fg=red>false</>'
        ));
        $output->writeln(sprintf('· oldest settled-unfolded forked batch id    : %s', $diagnosis->settledUnfoldedBatchId !== null ? (string)$diagnosis->settledUnfoldedBatchId : '— (none)'));
        $output->writeln(sprintf('· has any unsettled forked batch             : %s', $diagnosis->hasUnsettledBatch ? 'YES' : 'no'));

        if ($diagnosis->tailsDisagree()) {
            $output->writeln("\n<fg=red;options=bold>⛔ DISAGREEMENT — the bounded derive tail hides an unanswered inbound message the full tail ends on.</>");
            $output->writeln('<fg=red>The compaction cover edge is at/after the newest unanswered owner message, so the derive path treats the conversation as ANSWERED when it is not. This is the loop/silent-drop bug.</>');
        }

        if ($diagnosis->wouldDrive()) {
            $output->writeln("\n<fg=green;options=bold>→ WOULD DRIVE a turn</> (a trigger is present on the derive window).");
        } else {
            $output->writeln("\n<fg=yellow;options=bold>→ noWork.reDerive</> — no trigger on the derive window. Resume releases "
                . ($diagnosis->hasUnsettledBatch ? 'AWAITING_DELEGATES' : 'IDLE')
                . ' and re-fires ONLY if work is owed on reload. If the FULL tail DOES have an unanswered message, that message is being silently left unanswered.');
        }
    }
}
