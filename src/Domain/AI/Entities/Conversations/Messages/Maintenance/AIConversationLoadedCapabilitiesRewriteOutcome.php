<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the background job that rewrites the stored loaded-capability sets did to ONE conversation
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::compactLoadedCapabilitiesOfConversation()}):
 * whether it rewrote the conversation, left it because it is in use, or stopped on a row another writer changed,
 * and the rows, bytes and checks it counted.
 */
class AIConversationLoadedCapabilitiesRewriteOutcome extends ValueObject
{
    /** @var string The conversation was read to its end (rows rewritten or nothing to rewrite) */
    public const string OUTCOME_DONE = 'done';

    /** @var string The conversation had a message within the quiet period and was left alone */
    public const string OUTCOME_ACTIVE = 'active';

    /** @var string A row changed between the read and the write; the conversation is tried again */
    public const string OUTCOME_CONFLICT = 'conflict';

    /** @var string One of the OUTCOME_* values */
    public string $outcome = self::OUTCOME_DONE;

    /** @var int How many message rows were rewritten (in a dry run: would be rewritten) */
    public int $rowsRewritten = 0;

    /** @var int The bytes the rewritten rows' sets held before */
    public int $bytesBefore = 0;

    /** @var int The bytes the rewritten rows' sets hold afterwards */
    public int $bytesAfter = 0;

    /** @var int The duration of the slowest write in milliseconds */
    public int $slowestWriteMs = 0;

    /** @var int How many rows were read back and compared with the set they stored before */
    public int $verifiedRows = 0;

    /** @var int How many of the compared rows resolve to another set than before */
    public int $verifyMismatches = 0;

    /** @return bool Whether the conversation was left alone because it is in use */
    public function isActive(): bool
    {
        return $this->outcome === self::OUTCOME_ACTIVE;
    }

    /** @return bool Whether a row changed between the read and the write */
    public function isConflict(): bool
    {
        return $this->outcome === self::OUTCOME_CONFLICT;
    }
}
