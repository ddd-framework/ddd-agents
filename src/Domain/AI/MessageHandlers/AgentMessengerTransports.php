<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

/**
 * The Messenger transports the agent's workers consume, and which message goes to which transport. The handlers bind
 * to these names (`#[AsMessageHandler(fromTransport: …)]`); an application defines one transport per name in its
 * `messenger.yaml` (DSN, retry), routes the messages of {@see self::ROUTING_BY_MESSAGE_CLASS} to them and runs one worker
 * pool per transport. The transport contract test of the package
 * (`tests/Contract/MessengerTransportsHaveWorkerBlocksContract.php`) checks an application's configuration against
 * this class.
 *
 * The framework registers no routing and no transport for the agent: this class is the list a host copies. The host
 * writes each transport of {@see self::ALL} and each line of {@see self::ROUTING_BY_MESSAGE_CLASS} into its
 * own messenger configuration; a message the host does not route runs synchronously in the dispatching process.
 *
 * Pools and limits (the reference sizing):
 * - {@see self::TURN}: one message = one agent turn, a long job (30 min CPU ceiling in the handler); the pool's stop
 *   grace must cover a whole turn;
 * - {@see self::COMPACTION}: compaction, the idle finalize, the display summary and the run report mail — the same
 *   weight class, one pool;
 * - {@see self::SCORECARD}: the quality assessment of a finished conversation;
 * - {@see self::MESSAGE_EMBEDDING}: the embedding of one message;
 * - {@see self::TOOL_RESULT_SCREENING}: the injection screen of an untrusted tool result (the turn never waits for it);
 * - {@see self::ACTIVITY_LINES}: the narration of what the agent does while it works.
 */
class AgentMessengerTransports
{
    /** @var string One agent turn per message ({@see AIConversationResumeMessage}) */
    public const string TURN = 'ai_conversation_turn';

    /** @var string Compaction, finalize, summary and report mail */
    public const string COMPACTION = 'ai_conversation_compaction';

    /** @var string The quality assessment of a conversation */
    public const string SCORECARD = 'ai_conversation_scorecard';

    /** @var string The embedding of one conversation message */
    public const string MESSAGE_EMBEDDING = 'ai_conversation_message_embedding';

    /** @var string The background injection screen of an untrusted tool result */
    public const string TOOL_RESULT_SCREENING = 'ai_tool_result_screening';

    /** @var string The activity narration of a running turn */
    public const string ACTIVITY_LINES = 'ai_activity_lines';

    /** @var string[] Every transport of the agent */
    public const array ALL = [
        self::TURN,
        self::COMPACTION,
        self::SCORECARD,
        self::MESSAGE_EMBEDDING,
        self::TOOL_RESULT_SCREENING,
        self::ACTIVITY_LINES,
    ];

    /** @var array<class-string, string> The routing an application's `messenger.yaml` carries: message class => transport */
    public const array ROUTING_BY_MESSAGE_CLASS = [
        AIConversationResumeMessage::class => self::TURN,
        AIConversationCompactionMessage::class => self::COMPACTION,
        AIConversationFinalizeMessage::class => self::COMPACTION,
        AIConversationSummarizeMessage::class => self::COMPACTION,
        AIConversationReportEmailSendMessage::class => self::COMPACTION,
        AIConversationScorecardMessage::class => self::SCORECARD,
        AIConversationMessageEmbeddingMessage::class => self::MESSAGE_EMBEDDING,
        AgentToolResultScreeningMessage::class => self::TOOL_RESULT_SCREENING,
        AgentActivityLinesNarrateMessage::class => self::ACTIVITY_LINES,
    ];
}
