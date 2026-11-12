<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One conversation's worth of cross-conversation search results: the global "search all my chats" endpoint
 * groups the flat {@see SemanticSearchHit}s BY conversation so the frontend shows "in your chat '<title>' on <date> you
 * said …" rather than a flat scatter of message snippets. `relevance` is the group's best hit relevance (its sort key);
 * `hits` are that conversation's matched messages, best-first.
 */
class AIConversationSearchGroup extends ValueObject
{
    /** @var int|null The conversation the hits belong to. */
    public ?int $conversationId = null;

    /** @var string|null The conversation's title (its persisted summary title); null when it was never summarised. */
    public ?string $title = null;

    /** @var string|null The conversation's persisted summary text (a short recap shown under the title); null when it
     *  was never summarised. Read-only — never triggers summary generation. */
    public ?string $summary = null;

    /** @var string|null When the conversation was created, in `Y-m-d H:i:s` format (its changeHistory createdTime). */
    public ?string $created = null;

    /** @var string|null When the conversation was last modified, in `Y-m-d H:i:s` format (its changeHistory
     *  modifiedTime) — i.e. its most-recent activity; null when it was never modified after creation. */
    public ?string $updated = null;

    /** @var float The group's relevance = its best hit's relevance (the cross-conversation sort key). */
    public float $relevance = 0.0;

    /** @var SemanticSearchHit[] This conversation's matched messages, best-first. */
    public array $hits = [];
}
