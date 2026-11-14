<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbeddings;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusSearchRerank;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddings;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\SemanticSearchService;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Diagnoses the conversation recall search stage by stage, without the HTTP endpoint (no authentication refusal, real
 * stack traces from the running code). The search has several places where it can go empty silently; the command
 * prints the hit count at each:
 *
 *   embedding rows of the conversation → raw vector query → fused and reranked pipeline → grouped (what a frontend gets)
 *
 * It reproduces the two parts of the HTTP context a local reproduction would otherwise get wrong:
 *   1. RIGHTS — the cross-conversation search filters on the account's conversations itself, and the read rights of
 *      the message repository apply on top. The vector query runs both with the rights lifted (the query and the
 *      account filter) and with the rights of the real account. Hits without rights but none with them ⇒ a rights
 *      problem; none without rights ⇒ the vector query or missing embeddings.
 *   2. ENVELOPE — the endpoint opens a usage envelope, so the model rerank runs. Without an open envelope the rerank
 *      falls back to plain vector order, which hides a bad reranker. The grouped call runs inside the search envelope,
 *      and {@see ArgusSearchRerank} is probed directly so its raw verdict can be read.
 *
 *   php bin/console app:ai-conversation:search-inspect 3994 "invoice reminder"
 *   php bin/console app:ai-conversation:search-inspect 3994 "invoice reminder" --account=2857925 --embed
 *
 * Read-only by default; `--embed` embeds the conversation's missing text messages synchronously first (to rule out a
 * coverage gap). It only calls public service and repository methods; it never re-implements the search.
 */
#[AsCommand(
    name: 'app:ai-conversation:search-inspect',
    description: 'Diagnose conversation-recall search stage-by-stage (vector → rerank → grouped), rights off/on + envelope on.'
)]
class AISearchInspectCommand extends AgentConsoleCommand
{
    /** @var int Hits asked from every search stage */
    public const int HIT_LIMIT = 10;

    /** @var int Characters of an excerpt */
    public const int EXCERPT_CHARS = 200;

    protected function configure(): void
    {
        $this
            ->addArgument('conversationId', InputArgument::REQUIRED, 'Conversation id (names the account and enables the within-conversation stage)')
            ->addArgument('query', InputArgument::REQUIRED, 'Search query')
            ->addOption('account', null, InputOption::VALUE_OPTIONAL, 'Account id for the stage with rights (default: the conversation\'s account)')
            ->addOption('embed', null, InputOption::VALUE_NONE, 'Embed the conversation\'s text messages without an embedding synchronously first (rule out a coverage gap)');
    }

    /**
     * Runs the stages: coverage, vector query without and with rights, pipeline without and with envelope, rerank
     * probe, within-conversation search, context window around the top hit.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $conversationId = (int)$input->getArgument('conversationId');
        $query = (string)$input->getArgument('query');

        // the set-up loads run with the rights lifted; the restore before the stage with rights brings the real state back
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $entityRightsRestrictionsLifted = true;
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $conversation = $aIConversationsService->find($conversationId);
            if (!$conversation instanceof AIConversation) {
                $symfonyStyle->error("conversation $conversationId not found");
                return Command::FAILURE;
            }
            $accountId = $input->getOption('account') !== null ? (int)$input->getOption('account') : (int)($conversation->accountId ?? 0);
            $symfonyStyle->writeln("conv <info>$conversationId</info> · account <info>$accountId</info> · query \"<info>$query</info>\""
                . ' · created=' . ($conversation->getCreatedTime()?->format('Y-m-d H:i:s') ?? '-')
                . ' updated=' . ($conversation->getModifiedTime()?->format('Y-m-d H:i:s') ?? '-'));

            if ((bool)$input->getOption('embed')) {
                $this->embedMessagesOfConversation($conversation, $symfonyStyle);
            }
            $this->printEmbeddingCoverage($conversation, $symfonyStyle);

            /** @var AITextEmbeddingsService $aITextEmbeddingsService */
            $aITextEmbeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
            try {
                $queryEmbedding = $aITextEmbeddingsService->generateEmbeddingForText($query);
            } catch (TextEmbeddingNotGeneratedException $embeddingNotGeneratedException) {
                // every later stage searches with this vector
                $symfonyStyle->error('query embedding: NOT GENERATED — ' . $embeddingNotGeneratedException->getMessage());
                return Command::FAILURE;
            }
            $symfonyStyle->writeln('query embedding: <info>ok</info> (' . count($queryEmbedding->vectorValues ?? []) . 'd)');

            /** @var AccountsService $accountsService */
            $accountsService = DDDService::instance()->getService(AccountsService::class);
            $account = $accountsService->find($accountId);
            if (!$account instanceof Account) {
                $symfonyStyle->error("account $accountId not loadable — the search runs on the conversations of an account");
                return Command::FAILURE;
            }
            $messagesRepository = $this->getMessagesRepository();

            $symfonyStyle->section("(1) cross-conv vector query — RIGHTS OFF (conversations of account $accountId)");
            $this->printMessageHits($symfonyStyle, $messagesRepository->findSimilarAcrossConversationsByEmbedding($account, $queryEmbedding, self::HIT_LIMIT)?->getElements() ?? []);

            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            $entityRightsRestrictionsLifted = false;
            $this->actAsAccount($account);
            $symfonyStyle->section("(2) cross-conv vector query — RIGHTS ON (account $accountId)");
            $this->printMessageHits($symfonyStyle, $messagesRepository->findSimilarAcrossConversationsByEmbedding($account, $queryEmbedding, self::HIT_LIMIT)?->getElements() ?? []);

            /** @var SemanticSearchService $semanticSearchService */
            $semanticSearchService = DDDService::instance()->getService(SemanticSearchService::class);
            $this->printPipelineWithoutAndWithEnvelope($semanticSearchService, $account, $query, $symfonyStyle);
            $this->printRerankProbe($semanticSearchService, $messagesRepository, $account, $query, $queryEmbedding, $symfonyStyle);
            $topHitMessageId = $this->printWithinConversationSearch($semanticSearchService, $conversation, $query, $symfonyStyle);
            $this->printContextWindowAroundHit($topHitMessageId, $symfonyStyle);
            return Command::SUCCESS;
        } finally {
            if ($entityRightsRestrictionsLifted) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /**
     * Embeds every message of the conversation synchronously, so "no hits" cannot be a missing embedding.
     *
     * @param AIConversation $conversation
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function embedMessagesOfConversation(AIConversation $conversation, SymfonyStyle $symfonyStyle): void
    {
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $embeddedCount = 0;
        foreach ($aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation)?->getElements() ?? [] as $message) {
            try {
                $aIConversationMessagesService->embedMessage($message, async: false);
                $embeddedCount++;
            } catch (Throwable $embeddingError) {
                $symfonyStyle->warning("embed msg {$message->id} failed: " . $embeddingError->getMessage());
            }
        }
        $symfonyStyle->writeln("--embed: sync-embedded <info>$embeddedCount</info> messages");
    }

    /**
     * How many non-empty text messages of the conversation have an embedding row (none → the within-conversation
     * search is empty by design, not by a defect).
     *
     * @param AIConversation $conversation
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printEmbeddingCoverage(AIConversation $conversation, SymfonyStyle $symfonyStyle): void
    {
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        /** @var DBAIConversationMessageEmbeddings $messageEmbeddingsRepository */
        $messageEmbeddingsRepository = AIConversationMessageEmbeddings::getRepoClassInstance();
        $textMessageCount = 0;
        $embeddedMessageCount = 0;
        foreach ($aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation)?->getElements() ?? [] as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT || trim((string)($message->message ?? '')) === '') {
                continue;
            }
            $textMessageCount++;
            if (isset($message->id) && $messageEmbeddingsRepository->findByMessageId((int)$message->id) !== null) {
                $embeddedMessageCount++;
            }
        }
        $symfonyStyle->writeln("embedding rows in this conv: <info>$embeddedMessageCount</info> / $textMessageCount non-empty TEXT");
    }

    /**
     * The fused pipeline without an envelope (the rerank falls back to vector order) and the grouped result with the
     * search envelope open (the rerank runs).
     *
     * @param SemanticSearchService $semanticSearchService
     * @param Account $account
     * @param string $query
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printPipelineWithoutAndWithEnvelope(SemanticSearchService $semanticSearchService, Account $account, string $query, SymfonyStyle $symfonyStyle): void
    {
        $symfonyStyle->section('(3) full pipeline — NO envelope (rerank fail-open) vs WITH envelope (rerank runs)');
        try {
            $hitsWithoutEnvelope = $semanticSearchService->searchAcrossConversations($account, $query, self::HIT_LIMIT, self::EXCERPT_CHARS);
            $symfonyStyle->writeln('NO envelope: <info>' . count($hitsWithoutEnvelope) . '</info> hits');
        } catch (Throwable $searchError) {
            $symfonyStyle->error('NO envelope THREW: ' . $searchError->getMessage());
        }
        try {
            $usageEnvelopeContext = $this->openUsageEnvelope((int)$account->id, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SEARCH, AIUsageInitiator::ACCOUNT);
            try {
                $hitGroups = $semanticSearchService->searchAcrossConversationsGrouped($account, $query, self::HIT_LIMIT, self::EXCERPT_CHARS);
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
            $symfonyStyle->writeln('WITH envelope, GROUPED (= what the frontend gets): <info>' . count($hitGroups) . '</info> groups');
            foreach ($hitGroups as $hitGroup) {
                $symfonyStyle->writeln('  conv#' . ($hitGroup->conversationId ?? '?') . ' "' . ($hitGroup->title ?? '-') . '"'
                    . ' created=' . ($hitGroup->created ?? '-') . ' updated=' . ($hitGroup->updated ?? '-')
                    . ' hits=' . count($hitGroup->hits));
                foreach ($hitGroup->hits as $hit) {
                    $symfonyStyle->writeln('    #' . ($hit->messageId ?? '?') . ' ' . ($hit->role ?? '-')
                        . ' created=' . ($hit->created ?? '-') . ' updated=' . ($hit->updated ?? '-'));
                }
            }
        } catch (Throwable $searchError) {
            $symfonyStyle->error('grouped THREW: ' . $searchError->getMessage());
        }
    }

    /**
     * Feeds the top vector candidates to the rerank inside the search envelope and prints its raw verdict; an unranked
     * verdict prints the raw model response (the pipeline swallows that failure).
     *
     * @param SemanticSearchService $semanticSearchService
     * @param DBAIConversationMessages $messagesRepository
     * @param Account $account
     * @param string $query
     * @param TextEmbedding $queryEmbedding
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printRerankProbe(
        SemanticSearchService $semanticSearchService,
        DBAIConversationMessages $messagesRepository,
        Account $account,
        string $query,
        TextEmbedding $queryEmbedding,
        SymfonyStyle $symfonyStyle
    ): void {
        $symfonyStyle->section('(4) rerank probe — raw verdict on the top vector candidates');
        try {
            $candidateTexts = [];
            foreach ($messagesRepository->findSimilarAcrossConversationsByEmbedding($account, $queryEmbedding, 8)?->getElements() ?? [] as $candidateMessage) {
                $candidateTexts[] = $semanticSearchService->windowExcerpt((string)($candidateMessage->message ?? ''), $query, 400);
            }
            $searchRerank = new ArgusSearchRerank();
            $searchRerank->query = $query;
            $searchRerank->candidateTexts = $candidateTexts;
            $searchRerank->budgetAccount = $account;
            $usageEnvelopeContext = $this->openUsageEnvelope((int)$account->id, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SEARCH, AIUsageInitiator::ACCOUNT);
            try {
                $searchRerank->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
            $symfonyStyle->writeln('candidates in: ' . count($candidateTexts) . ' | reranked=' . ($searchRerank->reranked ? 'true' : 'false') . ' | rankedIndices=[' . implode(',', $searchRerank->rankedIndices) . ']');
            if (!$searchRerank->reranked) {
                $symfonyStyle->writeln('raw response (unranked — the swallowed failure): ' . mb_substr(json_encode($searchRerank->lastRawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '(null)', 0, 1200));
            }
            if ($searchRerank->reranked && count($searchRerank->rankedIndices) < (int)floor(count($candidateTexts) / 2)) {
                $symfonyStyle->warning('the reranker kept < half the candidates — if they were obviously relevant, the rerank model is the suspect (the recall floor keeps the search non-empty regardless).');
            }
        } catch (Throwable $rerankError) {
            $symfonyStyle->error('rerank probe THREW: ' . $rerankError->getMessage());
        }
    }

    /**
     * @param SemanticSearchService $semanticSearchService
     * @param AIConversation $conversation
     * @param string $query
     * @param SymfonyStyle $symfonyStyle
     * @return int|null the message id of the top hit
     */
    protected function printWithinConversationSearch(SemanticSearchService $semanticSearchService, AIConversation $conversation, string $query, SymfonyStyle $symfonyStyle): ?int
    {
        $symfonyStyle->section('(5) within-conversation search');
        try {
            $withinConversationHits = $semanticSearchService->searchWithinConversation($conversation, $query, self::HIT_LIMIT, self::EXCERPT_CHARS);
            $symfonyStyle->writeln('searchWithinConversation: <info>' . count($withinConversationHits) . '</info> hits');
            foreach ($withinConversationHits as $withinConversationHit) {
                $symfonyStyle->writeln('  #' . ($withinConversationHit->indexInConversation ?? '-') . ' [' . ($withinConversationHit->role ?? '-') . '] ' . mb_substr($withinConversationHit->excerpt ?? '', 0, 70));
            }
            $topHitMessageId = $withinConversationHits[0]->messageId ?? null;
            return $topHitMessageId !== null ? (int)$topHitMessageId : null;
        } catch (Throwable $searchError) {
            $symfonyStyle->error('searchWithinConversation THREW: ' . $searchError->getMessage());
            return null;
        }
    }

    /**
     * The context window the recall companion loads around the top hit.
     *
     * @param int|null $topHitMessageId
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printContextWindowAroundHit(?int $topHitMessageId, SymfonyStyle $symfonyStyle): void
    {
        $symfonyStyle->section('(6) loadMessageContext around the top hit');
        if ($topHitMessageId === null) {
            $symfonyStyle->writeln('no within-conv hit to centre on — skipped');
            return;
        }
        try {
            $contextWindow = $this->getAIConversationMessagesService()->loadMessageContext($topHitMessageId, 500, 800, 400);
            if ($contextWindow === null) {
                $symfonyStyle->warning("message #$topHitMessageId not loadable");
                return;
            }
            $symfonyStyle->writeln('window: <info>' . count($contextWindow->entries) . '</info> lines · ≈' . $contextWindow->approximateTokens . ' tokens');
            $symfonyStyle->writeln($contextWindow->toLlmMarkdown());
        } catch (Throwable $contextError) {
            $symfonyStyle->error('loadMessageContext THREW: ' . $contextError->getMessage());
        }
    }

    /**
     * @param SymfonyStyle $symfonyStyle
     * @param AIConversationMessage[] $messageHits
     * @return void
     */
    protected function printMessageHits(SymfonyStyle $symfonyStyle, array $messageHits): void
    {
        $symfonyStyle->writeln('hits: <info>' . count($messageHits) . '</info>');
        foreach (array_slice($messageHits, 0, 5) as $messageHit) {
            $symfonyStyle->writeln('  conv#' . ($messageHit->aiConversationId ?? '?') . ' #' . ($messageHit->indexInConversation ?? '-') . ' ' . mb_substr(trim((string)($messageHit->message ?? '')), 0, 60));
        }
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
