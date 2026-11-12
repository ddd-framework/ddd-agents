<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

/**
 * The KIND of one piece of an agent model call's context. Used to colour-code and label the segment
 * breakdown the admin sees for a turn. The first group is the parts of the composed SYSTEM message (in the order
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::composeSystemContent()} concatenates them); the
 * second group is the tool-schema block; the third is the wire message array.
 */
enum AgentContextSegmentType: string
{
    // --- system-message parts (compose order) ---
    case BASE_PROMPT = 'BASE_PROMPT';
    case SUB_AGENT_DIRECTIVE = 'SUB_AGENT_DIRECTIVE';
    /** The conversation PURPOSE directive: a purposed conversation's mission + prohibitions, right after the sub-agent directive. Per-conversation stable → extends the cacheable prefix. */
    case PURPOSE_DIRECTIVE = 'PURPOSE_DIRECTIVE';
    /** The scheduled-run role directive ({@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::RUN_DIRECTIVE}): the run IS one firing of a schedule — executes the mission, never administers schedules. Right after the purpose directive; static → extends the cacheable prefix. */
    case SCHEDULED_RUN_DIRECTIVE = 'SCHEDULED_RUN_DIRECTIVE';
    /** The space's time-zone rule — stable per conversation, part of the cacheable prefix. */
    case TIME_RULE = 'TIME_RULE';
    /** Who the agent is talking to ({@see \DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence}): present in the chat, on the phone, an external agent, nobody. Right after the time rule; roots only; stable per conversation except at a phone call's start and end → part of the cacheable prefix. */
    case OWNER_PRESENCE = 'OWNER_PRESENCE';
    case MEMORY_DIRECTIVES = 'MEMORY_DIRECTIVES';
    /** The cross-session narrative snapshot: recent sessions + importance survivors, stable per conversation. */
    case EPISODIC_HISTORY = 'EPISODIC_HISTORY';
    /**
     * The day context as it stood when the conversation was created: the text the application composed for the day.
     * Written once, stable per conversation.
     */
    case DAY_CONTEXT = 'DAY_CONTEXT';
    /**
     * The local situation as it stood when the conversation was created: the text the application composed about what
     * goes on around the conversation's space. Written once, stable per conversation.
     */
    case LOCAL_SITUATION = 'LOCAL_SITUATION';
    case PRODUCT_CAPABILITIES = 'PRODUCT_CAPABILITIES';
    /**
     * What the space HAS but this USER may not use — one line per capability with the right that unlocks it. Directly
     * after the plan block; never an upgrade widget, because a right is granted by the account administrator, not
     * bought.
     */
    case RESTRICTED_CAPABILITIES = 'RESTRICTED_CAPABILITIES';
    /** The stable, number-free AI-budget policy awareness (fair-use / hidden / has-a-budget) so the agent knows a cap exists. */
    case AI_BUDGET_AWARENESS = 'AI_BUDGET_AWARENESS';
    /**
     * The skill CATALOG the model may load from: one line per offered skill — name, purpose, the sub-skills
     * a root unlocks — rendered in the system prompt right before the loaded skills. Lists EVERY offered skill,
     * loaded ones included, so the block is byte-stable across loads; `load_skills` keeps only the enum.
     */
    case SKILL_CATALOG = 'SKILL_CATALOG';
    case LOADED_SKILLS = 'LOADED_SKILLS';
    case QUERY_OPTIONS_SYNTAX = 'QUERY_OPTIONS_SYNTAX';
    case SHARED_PARAMS = 'SHARED_PARAMS';
    case ALREADY_FETCHED_READS = 'ALREADY_FETCHED_READS';
    /** The wire-only task-plan tail hint: the model's per-step view of its current plan, before the reply-language cue. */
    case TASK_PLAN = 'TASK_PLAN';

    /** The write-carrying turn's answer contract — names the completed writes the final answer must report
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::completedWritesAnswerContractHint()}). */
    case ANSWER_CONTRACT = 'ANSWER_CONTRACT';
    case COMPACTION_SUMMARY = 'COMPACTION_SUMMARY';
    // --- tool schemas (the `tools` array on the wire) ---
    /** The loop's own meta-tools (`load_skills`, `load_tool`, `delegate`, the task-plan tools, …) — the per-turn constant part of the tools array. */
    case META_TOOL_SCHEMAS = 'META_TOOL_SCHEMAS';
    /** The loaded skills' tool schemas — the part of the tools array that grows and shrinks with the loaded set. */
    case TOOL_SCHEMAS = 'TOOL_SCHEMAS';
    // --- wire message array ---
    case USER_MESSAGE = 'USER_MESSAGE';
    case ASSISTANT_MESSAGE = 'ASSISTANT_MESSAGE';
    case TOOL_CALL = 'TOOL_CALL';
    case TOOL_RESULT = 'TOOL_RESULT';
    case SIGNAL = 'SIGNAL';
    /** The wire-only reply-language cue appended as the LAST user entry when the model generates after a tool result. */
    case REPLY_LANGUAGE_CUE = 'REPLY_LANGUAGE_CUE';
}
