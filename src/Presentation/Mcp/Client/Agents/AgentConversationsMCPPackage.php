<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AgentConversationRecallService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationReadMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationReadRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationSearchThisMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationSearchThisRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationsSearchMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationsSearchRequestDto;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Recall of what was said: earlier in the conversation the owner talks in (searchThisConversation), and in the owner's
 * earlier conversations — search them (titles and messages, fulltext and semantic) in every space the owner may read,
 * list those of a period, and read one page by page or around a match. Visibility is the chat's own list and search;
 * the audience rule keeps text of narrower spaces out of a shared conversation ({@see AgentConversationRecallService}).
 * The results are the owner's and other parties' text: untrusted, marked passage by passage.
 *
 * An application extends the recall through a same-path subclass of {@see AgentConversationRecallService} (the
 * audience of its conversations, the space label, the candidates, the rows) and the tools through a same-path subclass
 * of this package.
 */
class AgentConversationsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.conversations';

    public static ?string $description = 'Recall what was said: earlier in this conversation (searchThisConversation), in the owner\'s earlier conversations in every space they may read (searchConversations; without a query the conversations of a period), and the dialogue of one of them, also around a match (readConversation).';

    /**
     * Searches THIS conversation for what was said earlier, by meaning and by words: its messages and the summaries of
     * its compacted parts. For a detail that is no longer in your context (the owner named it earlier, you compared
     * something before). Each match names its message number; readConversation with this conversation's id and
     * aroundIndex reads the dialogue around it. Something discussed in an EARLIER conversation: searchConversations.
     *
     * @example {"query": "the competitor the owner asked me to compare against"}
     * @example {"query": "which city the second location is in", "limit": 3}
     */
    #[MCPTool]
    // never reused within the turn: a failed search answers with a note, a retry must search again
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolParallelSafe('reads conversation and message rows only')]
    #[MCPToolSummary('Searches THIS conversation for what was said earlier (messages + summaries of compacted parts, words and meaning) when a detail left your context; each match names its message number.')]
    public function searchThisConversation(
        AgentConversationSearchThisRequestDto $requestDto,
        AgentConversationRecallService $agentConversationRecallService,
        AgentToolCallProvenance $callProvenance
    ): AgentConversationSearchThisMcpResponseDto {
        $query = trim($requestDto->query);
        if ($query === '') {
            throw new BadRequestException('searchThisConversation needs a query: what was said that you are looking for.');
        }
        $currentConversation = $callProvenance->conversation;
        if (!$currentConversation instanceof AIConversation) {
            throw new BadRequestException('searchThisConversation searches the conversation the call runs in, and this call runs in none. Search earlier conversations with searchConversations.');
        }
        $thisConversation = $agentConversationRecallService->findThisConversation($currentConversation);
        $limit = $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : AgentConversationRecallService::DEFAULT_THIS_CONVERSATION_LIMIT;
        try {
            $hits = $agentConversationRecallService->searchThisConversation($thisConversation, $query, $limit);
        } catch (AgentToolExecutionTimeoutException $agentToolExecutionTimeoutException) {
            throw $agentToolExecutionTimeoutException;
        } catch (Throwable $searchError) {
            $this->logSearchFailure($searchError, (int)$thisConversation->id);
            return AgentConversationSearchThisMcpResponseDto::forFailedSearch($query, (int)$thisConversation->id);
        }
        return AgentConversationSearchThisMcpResponseDto::forHits($query, (int)$thisConversation->id, $hits);
    }

    /**
     * Searches the owner's EARLIER conversations in every space they may read: titles, summaries and messages
     * (exact words and meaning). Each result names its conversation id, space and best matches. Without a query: the
     * most recent conversations (with fromDate/toDate: those of a period, up to 25). Then read one with
     * readConversation, around a match with aroundIndex. The result names a ready widget block to SHOW the matches.
     *
     * @example {"query": "BMW iX Porsche Cayenne"}
     * @example {"query": "Kampagnenübersicht", "fromDate": "2026-11-01"}
     * @example {"fromDate": "2026-11-16", "toDate": "2026-11-22", "limit": 25}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads conversation and message rows only')]
    #[MCPToolSummary('Searches the owner\'s earlier conversations in all spaces they may read (titles + messages, words and meaning); each hit names its id and space. No query = most recent conversations or those of a period.')]
    public function searchConversations(
        AgentConversationsSearchRequestDto $requestDto,
        AgentConversationRecallService $agentConversationRecallService,
        AgentToolCallProvenance $callProvenance
    ): AgentConversationsSearchMcpResponseDto {
        $fromDate = $this->parseDay($requestDto->fromDate, 'fromDate', false);
        $toDate = $this->parseDay($requestDto->toDate, 'toDate', true);
        $result = $agentConversationRecallService->searchConversations(
            $this->requireActingAccount($callProvenance),
            $callProvenance->conversation,
            (string)($requestDto->query ?? ''),
            $this->findAgentSpaceIdOfSpaceFilter($requestDto->onlySpaceId),
            $fromDate,
            $toDate,
            $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : AgentConversationRecallService::DEFAULT_SEARCH_LIMIT
        );
        return AgentConversationsSearchMcpResponseDto::forResult($result, $callProvenance->toolCallId);
    }

    /**
     * Reads the dialogue of ONE conversation (owner and assistant messages, oldest first, page by page) by the id
     * searchConversations or searchThisConversation named: from fromIndex on, or with aroundIndex (a match's message
     * number) the messages around that match. A message named only by its id (a signal or a link: "message #3941258")
     * is read with aroundMessageId alone. The result names the fromIndex of the next page.
     *
     * @example {"conversationId": 31008}
     * @example {"conversationId": 31008, "fromIndex": 30}
     * @example {"conversationId": 31008, "aroundIndex": 14}
     * @example {"aroundMessageId": 3941258}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads conversation and message rows only')]
    #[MCPToolSummary('Reads one conversation by id (owner + assistant messages, paged; aroundIndex = the dialogue around a match).')]
    public function readConversation(
        AgentConversationReadRequestDto $requestDto,
        AgentConversationRecallService $agentConversationRecallService,
        AgentToolCallProvenance $callProvenance
    ): AgentConversationReadMcpResponseDto {
        $aroundMessageId = $requestDto->aroundMessageId !== null && $requestDto->aroundMessageId > 0 ? $requestDto->aroundMessageId : null;
        if ($requestDto->conversationId < 1 && $aroundMessageId === null) {
            throw new BadRequestException(
                'readConversation needs the conversationId that searchConversations or searchThisConversation named, or the aroundMessageId of a message named by its id.'
            );
        }
        $this->requireActingAccount($callProvenance);
        if ($aroundMessageId !== null) {
            return AgentConversationReadMcpResponseDto::forTranscript($agentConversationRecallService->readConversationAroundMessage(
                $callProvenance->conversation,
                $aroundMessageId,
                $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : AgentConversationRecallService::DEFAULT_READ_AROUND_LIMIT
            ));
        }
        $aroundIndex = $requestDto->aroundIndex !== null && $requestDto->aroundIndex >= 0 ? $requestDto->aroundIndex : null;
        $defaultLimit = $aroundIndex !== null ? AgentConversationRecallService::DEFAULT_READ_AROUND_LIMIT : AgentConversationRecallService::DEFAULT_READ_LIMIT;
        return AgentConversationReadMcpResponseDto::forTranscript($agentConversationRecallService->readConversation(
            $callProvenance->conversation,
            $requestDto->conversationId,
            max(0, (int)($requestDto->fromIndex ?? 0)),
            $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : $defaultLimit,
            $aroundIndex
        ));
    }

    /** Logs a failed search of this conversation (the model reads a note, the operators the issue) */
    protected function logSearchFailure(Throwable $searchError, int $conversationId): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable($searchError, LogLevel::WARNING, [
                'aiConversationId' => $conversationId,
                'consequence' => 'searchThisConversation answered without matches',
            ]);
        } catch (Throwable) {
            error_log('AgentConversationsMCPPackage: searchThisConversation failed — ' . $searchError->getMessage());
        }
    }

    /**
     * The space the filter `onlySpaceId` names: the number of a space label in a result, which the application's label
     * may take from another entity ({@see AgentSpacesService::findAgentSpaceIdOfLabelNumber()}); null = no filter.
     *
     * @throws BadRequestException when no space has that number
     */
    protected function findAgentSpaceIdOfSpaceFilter(?int $onlySpaceId): ?int
    {
        if ($onlySpaceId === null || $onlySpaceId < 1) {
            return null;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpaceId = $agentSpacesService->findAgentSpaceIdOfLabelNumber($onlySpaceId);
        if ($agentSpaceId === null) {
            throw new BadRequestException(
                "onlySpaceId $onlySpaceId names no space: take the number of a result's space label, or leave onlySpaceId out to search every space."
            );
        }
        return $agentSpaceId;
    }

    /**
     * The account the call acts for: the owner of the conversation, else the signed-in account.
     *
     * @throws ForbiddenException
     */
    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        $conversation = $callProvenance->conversation;
        $actingAccount = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$actingAccount instanceof Account || !isset($actingAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $actingAccount;
    }

    /**
     * A day `YYYY-MM-DD` as the start (or, with `$endOfDay`, the end) of that day; null for none.
     *
     * @throws BadRequestException
     */
    protected function parseDay(?string $day, string $argumentName, bool $endOfDay): ?DateTime
    {
        $day = $day !== null ? trim($day) : '';
        if ($day === '') {
            return null;
        }
        $dateTime = DateTime::createFromFormat('!Y-m-d', $day);
        if (!$dateTime instanceof \DateTime) {
            throw new BadRequestException("$argumentName is a day in the form YYYY-MM-DD.");
        }
        $parsedDay = new DateTime($dateTime->format('Y-m-d') . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
        return $parsedDay;
    }
}
