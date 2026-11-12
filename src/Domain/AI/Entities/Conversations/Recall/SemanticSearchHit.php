<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One result of a semantic conversation search — ALWAYS a MESSAGE, never a compaction node (the
 * node summaries are an internal recall accelerator; a node hit RESOLVES to the raw messages of its covered range).
 * The shared response unit of the REST search endpoints AND the agent recall tools (`recall_conversation` /
 * `search_memory`) — they differ only in the {@see $excerpt} size and whether {@see $conversationId}/{@see $title}
 * are filled (the global cross-conversation endpoint sets them; the within-conversation path leaves them null).
 */
class SemanticSearchHit extends ValueObject
{
    /** @var int|null The matched message's id (the click-through target / dedup key). */
    public ?int $messageId;

    /** @var int|null The message's position in its conversation (for ordering + ±context resolution). */
    public ?int $indexInConversation;

    /** @var string|null The message role (user / assistant / …). */
    public ?string $role;

    /** @var DateTime|null The instant the message was created (its changeHistory createdTime); the structured path
     *  renders it through the serializer's model-facing zone, the markdown path via {@see AgentClock::formatMoment()}. */
    public ?DateTime $created;

    /** @var DateTime|null The instant the message was last modified (its changeHistory modifiedTime); null when it
     *  was never edited after creation. */
    public ?DateTime $updated;

    /** @var string The excerpt, windowed to the request's `excerptChars` (0 = the full message text). */
    public string $excerpt;

    /** @var float The relevance score 0–1 (the LLM rerank score, or the fused vector rank when rerank is unavailable). */
    public float $relevance;

    /** @var int|null The conversation the hit belongs to — set ONLY by the global cross-conversation search. */
    public ?int $conversationId;

    /** @var string|null The conversation title — set ONLY by the global cross-conversation search. */
    public ?string $title;

    public function __construct(
        ?int $messageId,
        ?int $indexInConversation,
        ?string $role,
        ?DateTime $created,
        ?DateTime $updated,
        string $excerpt,
        float $relevance,
        ?int $conversationId = null,
        ?string $title = null,
    ) {
        parent::__construct();
        $this->messageId = $messageId;
        $this->indexInConversation = $indexInConversation;
        $this->role = $role;
        $this->created = $created;
        $this->updated = $updated;
        $this->excerpt = $excerpt;
        $this->relevance = $relevance;
        $this->conversationId = $conversationId;
        $this->title = $title;
    }

    /** One Markdown line for the agent tool path (`recall_conversation` / `search_memory`) — see the feedback note
     *  that every renderable object renders ITSELF; the tool composes these, never formats a hit's fields inline. */
    public function toLlmMarkdown(): string
    {
        // The conversation ref is shown ONLY for cross-conversation hits (it is null for within-conversation /
        // memory hits — there the chat is implicit) so the agent knows WHICH past chat a hit came from.
        $conversation = $this->conversationId !== null ? ' [conversation #' . $this->conversationId . ']' : '';
        $when = $this->created !== null ? ' (' . AgentClock::formatMoment($this->created) . ')' : '';
        // The `[#<messageId>]` is the loadMessageContext handle — the agent passes it to expand the dialogue around a
        // hit. Without it the within-conversation markdown path had only the position (no message id), so the
        // recall → loadMessageContext bridge was impossible. Memory hits (no messageId) just omit it.
        $handle = $this->messageId !== null ? '[#' . $this->messageId . '] ' : '';
        return '- ' . $handle . '**' . ($this->role ?? 'message') . '**' . $conversation . $when . ': ' . $this->excerpt;
    }
}
