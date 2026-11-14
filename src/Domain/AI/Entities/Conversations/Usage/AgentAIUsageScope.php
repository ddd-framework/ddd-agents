<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Usage;

/**
 * The AI usage scopes the agent core books under (model-call scopes and `ENVELOPE.` scopes). The application's scope
 * catalog lists them among its own scopes (by referencing these constants) until the scope registry of ddd-ai
 * collects scope sets.
 */
class AgentAIUsageScope
{
    /** A safety check on the AI assistant. */
    public const string AGENT_GUARD_SENTINEL_PROBE = 'AI.AGENT.PROBE.SENTINEL_GUARD';

    /** Agent — a TYPED decision at a turn boundary (the reply-language decision), asked of a System One decision model; counted against the usage cap like every op. */
    public const string AI_AGENT_DECIDE_TURN = 'AI.AGENT.DECIDE.TURN';

    /** Testing the assistant: the verdict on an evaluation run (never billed to you). */
    public const string AI_AGENT_JUDGE_EVALUATION = 'AI.AGENT.JUDGE.EVALUATION';

    /** Telling you what the assistant is doing while it works (one line per tool call, while you watch). */
    public const string AI_AGENT_NARRATE_ACTIVITY = 'AI.AGENT.NARRATE.ACTIVITY';

    /** Testing the assistant: the simulated owner's message in an evaluation run (never billed to you). */
    public const string AI_AGENT_SIMULATE_OWNER = 'AI.AGENT.SIMULATE.OWNER';

    /** Reviewing a finished assistant chat to improve the assistant (quality analysis, never billed to you). */
    public const string AI_CONVERSATION_ANALYZE = 'AI.CONVERSATION.ANALYZE.QUALITY';

    /** The catalogue of detail clusters — allocating analysis patterns to it and tidying it (the daily run). */
    public const string AI_CONVERSATION_ANALYZE_CLUSTERS = 'AI.CONVERSATION.ANALYZE.CLUSTERS';

    /** Condensing a long assistant chat to keep it responsive. */
    public const string AI_CONVERSATION_COMPACTION = 'AI.CONVERSATION.COMPACTION.HISTORY';

    /** Detecting which language you write in, to set your interface language. */
    public const string AI_CONVERSATION_DETECT_USER_LANGUAGE = 'AI.CONVERSATION.DETECT.USER_LANGUAGE';

    /** A message in the AI assistant chat. */
    public const string AI_CONVERSATION_SEND_MESSAGE = 'AI.CONVERSATION.SEND.MESSAGE';

    /** Creating the title and summary of an assistant chat. */
    public const string AI_CONVERSATION_SUMMARIZE = 'AI.CONVERSATION.SUMMARIZE.HISTORY';

    /** Checking whether a recurring task's goal is fulfilled. */
    public const string AI_SCHEDULE_JUDGE_DONE_CRITERIA = 'AI.SCHEDULE.JUDGE.DONE_CRITERIA';

    /** Ranking assistant search results by relevance. */
    public const string AI_SEARCH_RERANK = 'AI.SEARCH.RERANK.RELEVANCE';

    /** Safety check on assistant input. */
    public const string AI_SECURITY_DETECT_INJECTION = 'AI.SECURITY.DETECT.INJECTION';

    /** Testing the assistant: the model calls an evaluation run makes besides the assistant's own (never billed to you). */
    public const string ENVELOPE_AI_AGENT_EVALUATION = 'ENVELOPE.AI.AGENT.EVALUATION';

    /** Telling you what the assistant is doing while it works. */
    public const string ENVELOPE_AI_CONVERSATION_ACTIVITY = 'ENVELOPE.AI.CONVERSATION.ACTIVITY';

    /** Condensing a long assistant chat. */
    public const string ENVELOPE_AI_CONVERSATION_COMPACTION = 'ENVELOPE.AI.CONVERSATION.COMPACTION';

    /** Preparing assistant chats for search. */
    public const string ENVELOPE_AI_CONVERSATION_EMBEDDING = 'ENVELOPE.AI.CONVERSATION.EMBEDDING';

    /** Searching your assistant chats. */
    public const string ENVELOPE_AI_CONVERSATION_SEARCH = 'ENVELOPE.AI.CONVERSATION.SEARCH';

    /** Assistant chat title and summary. */
    public const string ENVELOPE_AI_CONVERSATION_SUMMARIZE = 'ENVELOPE.AI.CONVERSATION.SUMMARIZE';

    /** An AI assistant chat message. */
    public const string ENVELOPE_AI_CONVERSATION_TURN = 'ENVELOPE.AI.CONVERSATION.TURN';

    /** Updating what the assistant remembers. */
    public const string ENVELOPE_AI_MEMORY_CURATE = 'ENVELOPE.AI.MEMORY.CURATE';

    /** An AI assistant action. */
    public const string ENVELOPE_MCP_TOOL_CALL = 'ENVELOPE.MCP.TOOL_CALL';

    /** Verifying your approval before the assistant takes on a standing mandate. */
    public const string AI_MANDATE_CHECK_APPROVAL = 'AI.MANDATE.CHECK.APPROVAL';
}
