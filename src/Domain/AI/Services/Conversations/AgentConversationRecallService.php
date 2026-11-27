<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallResult;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallRow;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallTranscript;
use DDD\Domain\AI\Entities\Conversations\Recall\SemanticSearchHit;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\Service;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;

/**
 * The agent's recall of the owner's earlier conversations (tools `searchConversations` and `readConversation` of
 * {@see \DDD\Presentation\Mcp\Client\Agents\AgentConversationsMCPPackage}).
 *
 * Visibility is the one of the chat's own list and search: the conversations of the acting account in EVERY space,
 * read under the read rights of the conversation table, customer-visible, the owner's dialogue only
 * ({@see DBAIConversations::restrictToConversationsTheSearchAcrossConversationsAnswersFrom()}); the message search is
 * {@see SemanticSearchService::searchAcrossConversations()}, the search box of the chat. No second rights logic.
 *
 * Audience rule (as for connections): text of a conversation flows into the current conversation only when the
 * readers of the source are not narrower than the readers of the current one. A conversation only its owner reads
 * receives from every visible conversation; a conversation others read ({@see isConversationReadByOthers()}) receives
 * only from conversations of its own space. Without a current conversation (an MCP client) there is no audience to
 * protect.
 */
class AgentConversationRecallService extends Service
{
    public const int DEFAULT_SEARCH_LIMIT = 6;
    public const int MAX_SEARCH_LIMIT = 15;
    public const int HITS_PER_CONVERSATION = 2;
    public const int EXCERPT_CHARS = 320;
    public const int DEFAULT_READ_LIMIT = 30;
    public const int MAX_READ_LIMIT = 80;
    public const int MAX_READ_MESSAGE_CHARS = 1500;

    /**
     * Searches the account's conversations: titles and summaries (any word) and messages (fulltext + semantic). An
     * empty query lists the most recent conversations. The current conversation is left out.
     *
     * @throws ForbiddenException when cross-conversation recall is switched off for the current conversation
     */
    public function searchConversations(
        Account $account,
        ?AIConversation $currentConversation,
        string $query,
        ?int $agentSpaceId,
        ?DateTime $from,
        ?DateTime $to,
        int $limit
    ): AgentConversationRecallResult {
        $this->requireRecallEnabled($currentConversation);
        $limit = max(1, min($limit, static::MAX_SEARCH_LIMIT));
        $query = trim($query);
        $currentConversationId = isset($currentConversation->id) ? (int)$currentConversation->id : null;

        /** @var array<int, SemanticSearchHit[]> $hitsByConversationId best conversation first */
        $hitsByConversationId = [];
        if ($query !== '') {
            /** @var SemanticSearchService $semanticSearchService */
            $semanticSearchService = DDDService::instance()->getService(SemanticSearchService::class);
            $hits = $semanticSearchService->searchAcrossConversations($account, $query, $limit * 4, static::EXCERPT_CHARS);
            foreach ($hits as $hit) {
                if ($hit->conversationId === null || $hit->conversationId === $currentConversationId) {
                    continue;
                }
                if (!$this->isMomentInWindow($hit->created, $from, $to)) {
                    continue;
                }
                $hitsByConversationId[$hit->conversationId][] = $hit;
            }
        }

        $candidateConversations = $this->findRecallCandidates($account, $this->splitIntoWords($query), $agentSpaceId, $from, $to, $limit * 2);
        /** @var array<int, AIConversation> $conversationsById */
        $conversationsById = [];
        foreach ($candidateConversations?->getElements() ?? [] as $conversation) {
            $conversationsById[(int)$conversation->id] = $conversation;
        }

        $orderedConversationIds = array_keys($hitsByConversationId);
        foreach (array_keys($conversationsById) as $conversationId) {
            if (!in_array($conversationId, $orderedConversationIds, true)) {
                $orderedConversationIds[] = $conversationId;
            }
        }

        $result = new AgentConversationRecallResult();
        $result->query = $query;
        $result->currentConversationIsShared = $currentConversation !== null && $this->isConversationSharedWithOthers($currentConversation);
        foreach ($orderedConversationIds as $conversationId) {
            if ($conversationId === $currentConversationId || $result->rows->count() >= $limit) {
                continue;
            }
            $conversation = $conversationsById[$conversationId] ?? $this->findReadableConversation($conversationId);
            if (!$conversation instanceof AIConversation) {
                continue; // not readable under the rights or hidden: as if it did not exist
            }
            if ($agentSpaceId !== null && (int)($conversation->agentSpaceId ?? 0) !== $agentSpaceId) {
                continue;
            }
            if (!$this->mayContentFlowInto($conversation, $currentConversation)) {
                $result->leftOutForAudienceCount++;
                continue;
            }
            $row = $this->buildRow($conversation, array_slice($hitsByConversationId[$conversationId] ?? [], 0, static::HITS_PER_CONVERSATION));
            $result->rows->add($row);
        }
        return $result;
    }

    /**
     * One page of the dialogue of a conversation the account may read.
     *
     * @throws NotFoundException for an unknown, unreadable or hidden conversation
     * @throws ForbiddenException when the audience rule keeps its text out of the current conversation
     */
    public function readConversation(?AIConversation $currentConversation, int $conversationId, int $fromIndex, int $limit): AgentConversationRecallTranscript
    {
        $this->requireRecallEnabled($currentConversation);
        $conversation = $this->findReadableConversation($conversationId);
        if (!$conversation instanceof AIConversation) {
            throw new NotFoundException("Conversation #$conversationId was not found or may not be read. Find conversations with searchConversations.");
        }
        if (!$this->mayContentFlowInto($conversation, $currentConversation)) {
            throw new ForbiddenException(
                "Conversation #$conversationId lies in a space with fewer readers than this conversation; its text is not shown here. The owner can read it from that space."
            );
        }
        $limit = max(1, min($limit, static::MAX_READ_LIMIT));
        /** @var DBAIConversationMessages $messagesRepository */
        $messagesRepository = AIConversationMessages::getRepoClassInstance();
        $messages = $messagesRepository->findContentMessagesFromIndex($conversationId, $fromIndex, $limit + 1)?->getElements() ?? [];

        $transcript = new AgentConversationRecallTranscript();
        $transcript->conversation = $this->buildRow($conversation, []);
        foreach (array_slice(array_values($messages), 0, $limit) as $message) {
            $recallMessage = $this->buildRecallMessage($message);
            $transcript->messages->add($recallMessage);
        }
        if (count($messages) > $limit) {
            $transcript->nextFromIndex = (int)$messages[$limit]->indexInConversation;
        }
        return $transcript;
    }

    /**
     * HOST HOOK (audience rule): whether people other than its owner read `$conversation` (a shared space). Default:
     * true (fail closed): such a conversation receives text only from conversations of its own space.
     */
    public function isConversationReadByOthers(AIConversation $conversation): bool
    {
        return true;
    }

    /**
     * Whether others read `$conversation`: a thread of a shared chat channel (AgentConnections, while enabled) or the
     * host's answer ({@see isConversationReadByOthers()}).
     */
    public function isConversationSharedWithOthers(AIConversation $conversation): bool
    {
        if (isset($conversation->id)) {
            /** @var AgentConnectionsService $agentConnectionsService */
            $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
            if ($agentConnectionsService->isSharedChatThread((int)$conversation->id)) {
                return true;
            }
        }
        return $this->isConversationReadByOthers($conversation);
    }

    /**
     * HOST HOOK: how a space is named to the model beside a result. Default: the label of
     * {@see AgentSpacesService::buildAgentSpaceLabelForTheModel()}, `account level` without a space.
     */
    public function describeAgentSpace(?int $agentSpaceId): string
    {
        if ($agentSpaceId === null) {
            return 'account level';
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->buildAgentSpaceLabelForTheModel($agentSpaceId);
    }

    /** Whether text of `$sourceConversation` may be shown in `$targetConversation` (see the class comment) */
    public function mayContentFlowInto(AIConversation $sourceConversation, ?AIConversation $targetConversation): bool
    {
        if ($targetConversation === null || !$this->isConversationSharedWithOthers($targetConversation)) {
            return true;
        }
        $sourceSpaceId = $sourceConversation->agentSpaceId ?? null;
        $targetSpaceId = $targetConversation->agentSpaceId ?? null;
        return $sourceSpaceId !== null && $targetSpaceId !== null && (int)$sourceSpaceId === (int)$targetSpaceId;
    }

    /** @throws ForbiddenException */
    protected function requireRecallEnabled(?AIConversation $currentConversation): void
    {
        if ($currentConversation !== null && !$currentConversation->isCrossConversationRecallEnabled()) {
            throw new ForbiddenException('Recalling other conversations is switched off for this conversation.');
        }
    }

    /** The conversation under the read rights and customer visibility, else null */
    protected function findReadableConversation(int $conversationId): ?AIConversation
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        try {
            return $aIConversationsService->findVisibleToCustomer($conversationId);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * @param string[] $words
     */
    protected function findRecallCandidates(Account $account, array $words, ?int $agentSpaceId, ?DateTime $from, ?DateTime $to, int $limit): ?AIConversations
    {
        /** @var DBAIConversations $conversationsRepository */
        $conversationsRepository = AIConversations::getRepoClassInstance();
        return $conversationsRepository->findRecallCandidatesOfAccount((int)$account->id, $words, $agentSpaceId, $from, $to, $limit);
    }

    /**
     * The lower-case words of a query worth a title match (at least 2 characters), at most 8.
     *
     * @return string[]
     */
    protected function splitIntoWords(string $query): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [] as $word) {
            if (mb_strlen($word) >= 2 && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }
        return array_slice($words, 0, 8);
    }

    protected function isMomentInWindow(?DateTime $moment, ?DateTime $from, ?DateTime $to): bool
    {
        if ($moment === null) {
            return $from === null && $to === null;
        }
        return ($from === null || $moment >= $from) && ($to === null || $moment <= $to);
    }

    /**
     * @param SemanticSearchHit[] $hits
     */
    protected function buildRow(AIConversation $conversation, array $hits): AgentConversationRecallRow
    {
        $row = new AgentConversationRecallRow();
        $row->conversationId = (int)$conversation->id;
        $summary = $conversation->getSummaryWithoutGenerating();
        $row->title = isset($summary->title) ? $summary->title : null;
        $row->summary = isset($summary->text) ? $summary->text : null;
        $row->agentSpaceId = isset($conversation->agentSpaceId) ? (int)$conversation->agentSpaceId : null;
        $row->agentSpace = $this->describeAgentSpace($row->agentSpaceId);
        $row->created = $conversation->getCreatedTime();
        $row->updated = $conversation->getModifiedTime();
        $row->hits->add(...$hits);
        return $row;
    }

    protected function buildRecallMessage(AIConversationMessage $message): AgentConversationRecallMessage
    {
        $recallMessage = new AgentConversationRecallMessage();
        $recallMessage->indexInConversation = (int)($message->indexInConversation ?? 0);
        $recallMessage->role = (string)($message->role ?? '');
        $recallMessage->created = $message->getCreatedTime();
        $text = AIConversationMessage::toSearchableText((string)($message->message ?? ''));
        if (mb_strlen($text) > static::MAX_READ_MESSAGE_CHARS) {
            $text = mb_substr($text, 0, static::MAX_READ_MESSAGE_CHARS) . ' …';
        }
        $recallMessage->text = $text;
        return $recallMessage;
    }
}
