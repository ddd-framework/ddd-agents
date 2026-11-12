<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Reports;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The report a run owes its owner: the OWED stamp (a terminal of an unattended or
 * abnormal run stamped the obligation) and the DELIVERED stamp (the report reached the chat, or its delivery is claimed),
 * copied from the conversation's two columns by {@see AIConversation::getReportObligation()}. Reads nothing; the stamps
 * are written by the conversations service (targeted, claim-safe updates).
 */
class AIConversationReportObligation extends ValueObject
{
    /** When the obligation was stamped (`reportOwedAt`); null = nothing owed. */
    public ?DateTime $owedAt = null;

    /** When the report was delivered or its delivery claimed (`reportDeliveredAt`); null = not delivered. */
    public ?DateTime $deliveredAt = null;

    /** A run of this conversation owes (or owed) a report — the stamp the owed-reports pass orders by. */
    public function isOwed(): bool
    {
        return $this->owedAt !== null;
    }

    /** The report was delivered, or its delivery claimed (the claim stamps the same column). */
    public function isDelivered(): bool
    {
        return $this->deliveredAt !== null;
    }
}
