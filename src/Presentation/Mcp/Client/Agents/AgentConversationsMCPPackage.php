<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Conversations\AgentConversationRecallService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationReadMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationReadRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationsSearchMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentConversationsSearchRequestDto;

/**
 * Recall of the owner's earlier conversations: search them (titles and messages, fulltext and semantic) in every
 * space the owner may read, and read one page by page. Visibility is the chat's own list and search; the audience
 * rule keeps text of narrower spaces out of a shared conversation ({@see AgentConversationRecallService}). The
 * results are the owner's and other parties' text: untrusted, marked passage by passage.
 */
class AgentConversationsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.conversations';

    public static ?string $description = 'Search the owner\'s earlier conversations in every space they may read (searchConversations) and read one of them (readConversation).';

    /**
     * Searches the owner's EARLIER conversations in every space they may read: titles, summaries and messages
     * (exact words and meaning). Each result names its conversation id and space. Without a query: the most recent
     * conversations. Then read one with readConversation.
     *
     * @example {"query": "BMW iX Porsche Cayenne"}
     * @example {"query": "Kampagnenübersicht", "fromDate": "2026-11-01"}
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads conversation and message rows only')]
    #[MCPToolSummary('Searches the owner\'s earlier conversations in all spaces they may read (titles + messages, words and meaning); each hit names its id and space. No query = most recent conversations.')]
    public function searchConversations(
        AgentConversationsSearchRequestDto $requestDto,
        AgentConversationRecallService $agentConversationRecallService,
        AgentToolCallProvenance $callProvenance
    ): AgentConversationsSearchMcpResponseDto {
        $fromDate = $this->parseDay($requestDto->fromDate, 'fromDate', false);
        $toDate = $this->parseDay($requestDto->toDate, 'toDate', true);
        return AgentConversationsSearchMcpResponseDto::forResult($agentConversationRecallService->searchConversations(
            $this->requireActingAccount($callProvenance),
            $callProvenance->conversation,
            (string)($requestDto->query ?? ''),
            $requestDto->onlySpaceId !== null && $requestDto->onlySpaceId > 0 ? $requestDto->onlySpaceId : null,
            $fromDate,
            $toDate,
            $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : AgentConversationRecallService::DEFAULT_SEARCH_LIMIT
        ));
    }

    /**
     * Reads the dialogue of ONE earlier conversation (owner and assistant messages, oldest first, page by page) by
     * the id searchConversations named. The result names the fromIndex of the next page.
     *
     * @example {"conversationId": 31008}
     * @example {"conversationId": 31008, "fromIndex": 30}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads conversation and message rows only')]
    #[MCPToolSummary('Reads one earlier conversation by id (owner + assistant messages, paged).')]
    public function readConversation(
        AgentConversationReadRequestDto $requestDto,
        AgentConversationRecallService $agentConversationRecallService,
        AgentToolCallProvenance $callProvenance
    ): AgentConversationReadMcpResponseDto {
        if ($requestDto->conversationId < 1) {
            throw new BadRequestException('readConversation needs the conversationId that searchConversations named.');
        }
        $this->requireActingAccount($callProvenance);
        return AgentConversationReadMcpResponseDto::forTranscript($agentConversationRecallService->readConversation(
            $callProvenance->conversation,
            $requestDto->conversationId,
            max(0, (int)($requestDto->fromIndex ?? 0)),
            $requestDto->limit !== null && $requestDto->limit > 0 ? $requestDto->limit : AgentConversationRecallService::DEFAULT_READ_LIMIT
        ));
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
